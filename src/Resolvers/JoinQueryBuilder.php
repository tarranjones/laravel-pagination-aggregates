<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use LogicException;

/**
 * Builds one query that returns every aggregate in a single row.
 *
 * Each instruction group becomes a derived table that itself returns exactly one row, and the
 * derived tables are combined with CROSS JOIN:
 *
 * - base groups:          SELECT COUNT(*), SUM(col) ... FROM <base query + constraint>
 * - HasOne / HasMany /
 *   MorphOne / MorphMany: SELECT COUNT(*), SUM(col) ... FROM related
 *                         WHERE fk IN (SELECT key FROM <base query>) [AND morph type = ...]
 * - other relations:      correlated withAggregate() subqueries per base row, rolled up with
 *                         SUM / MIN / MAX over those rows.
 *
 * Only the base groups and the correlated rollup scan the base query; HasOne/HasMany aggregates
 * read only the related rows of matching parents, so indexes on the foreign key are used.
 */
class JoinQueryBuilder
{
    private const string PER_ROW_ALIAS = 'agg_rows';

    /**
     * @param  InstructionGroup[]  $groups
     * @param  Builder<*>  $builder
     * @return array{0: QueryBuilder, 1: array<string, string|array{sum: string, count: string}>}
     */
    public function build(array $groups, Builder $builder, Grammar $grammar): array
    {
        /** @var array<string, string|array{sum: string, count: string}> $existsCols */
        $existsCols = [];
        $joinAliasCounters = [];
        /** @var array<string, Builder<*>> $parts */
        $parts = [];
        /** @var InstructionGroup[] $perRowGroups */
        $perRowGroups = [];

        foreach ($groups as $group) {
            if ($group->type === 'base') {
                $parts[$this->joinAlias((string) $group->table, $joinAliasCounters)] = $this->baseGroupQuery($group, $builder, $grammar, $existsCols);
            } elseif ($group->isHasOneOrMany) {
                $parts[$this->joinAlias((string) $group->baseName, $joinAliasCounters)] = $this->hasOneOrManyGroupQuery($group, $builder, $grammar, $existsCols);
            } else {
                $perRowGroups[] = $group;
            }
        }

        if ($perRowGroups !== []) {
            $parts[self::PER_ROW_ALIAS] = $this->perRowRollupQuery($perRowGroups, $builder, $grammar, $existsCols);
        }

        return [$this->combine($parts), $existsCols];
    }

    /**
     * @param  array<string, Builder<*>>  $parts
     */
    private function combine(array $parts): QueryBuilder
    {
        if (count($parts) === 1) {
            return reset($parts)->toBase();
        }

        $query = null;

        foreach ($parts as $alias => $part) {
            $query = $query instanceof QueryBuilder
                ? $query->crossJoinSub($part, $alias)
                : $part->toBase()->newQuery()->fromSub($part, $alias);
        }

        if (! $query instanceof QueryBuilder) {
            throw new LogicException('No aggregate groups to combine.');
        }

        return $query->select('*');
    }

    /**
     * Base aggregates: one row computed over the base query (plus the group's constraint).
     *
     * @param  Builder<*>  $builder
     * @param  array<string, string|array{sum: string, count: string}>  $existsCols
     * @return Builder<*>
     */
    private function baseGroupQuery(InstructionGroup $instructionGroup, Builder $builder, Grammar $grammar, array &$existsCols): Builder
    {
        $subQuery = clone $builder;
        $subQuery->getQuery()->columns = null;

        if ($instructionGroup->constraints instanceof Closure) {
            ($instructionGroup->constraints)($subQuery);
        }

        // ORDER BY is meaningless inside an aggregate and rejected by PostgreSQL.
        return $subQuery->reorder()->selectRaw($this->aggregateSelects($instructionGroup, $grammar, $existsCols));
    }

    /**
     * HasOne / HasMany / MorphOne / MorphMany: aggregate the related rows whose foreign key is
     * one of the base query's keys. Equivalent to rolling up per-parent aggregates, because each
     * related row belongs to exactly one parent.
     *
     * @param  Builder<*>  $builder
     * @param  array<string, string|array{sum: string, count: string}>  $existsCols
     * @return Builder<*>
     */
    private function hasOneOrManyGroupQuery(InstructionGroup $instructionGroup, Builder $builder, Grammar $grammar, array &$existsCols): Builder
    {
        $relation = $instructionGroup->relation;

        if (! $relation instanceof HasOneOrMany) {
            throw new LogicException('Expected a HasOne / HasMany relation.');
        }

        $parentKeys = (clone $builder)->reorder();
        $parentKeys->getQuery()->columns = null;
        $parentKeys->select($relation->getQualifiedParentKeyName());

        $subQuery = $relation->getRelated()->newQuery();

        if ($relation instanceof MorphOneOrMany) {
            $subQuery->where($relation->getQualifiedMorphType(), $relation->getMorphClass());
        }

        if ($instructionGroup->constraints instanceof Closure) {
            ($instructionGroup->constraints)($subQuery);
        }

        return $subQuery
            ->whereIn($relation->getQualifiedForeignKeyName(), $parentKeys)
            ->selectRaw($this->aggregateSelects($instructionGroup, $grammar, $existsCols));
    }

    /**
     * Other relations: correlated withAggregate() subqueries give one value per base row, which
     * are then rolled up into a single row.
     *
     * @param  InstructionGroup[]  $groups
     * @param  Builder<*>  $builder
     * @param  array<string, string|array{sum: string, count: string}>  $existsCols
     * @return Builder<*>
     */
    private function perRowRollupQuery(array $groups, Builder $builder, Grammar $grammar, array &$existsCols): Builder
    {
        $perRow = (clone $builder)->reorder();
        $perRow->getQuery()->columns = null;
        $perRow->selectRaw('1 AS _agg_row');

        $selects = [];

        foreach ($groups as $group) {
            $this->buildNonHasOneOrManyGroup($group, $perRow, $existsCols);

            foreach ($group->instructions as $instruction) {
                $colInfo = $existsCols[$instruction->alias] ?? null;

                if ($colInfo === null) {
                    $fn = match ($instruction->function) {
                        'min' => 'MIN',
                        'max' => 'MAX',
                        default => 'SUM',
                    };
                    $selects[] = $this->rollup($fn, $instruction->alias, $grammar);
                } elseif (is_string($colInfo)) {
                    $selects[] = $this->rollup('SUM', $colInfo, $grammar);
                } else {
                    $selects[] = $this->rollup('SUM', $colInfo['sum'], $grammar);
                    $selects[] = $this->rollup('SUM', $colInfo['count'], $grammar);
                }
            }
        }

        /** @var literal-string $sql aggregate expressions over grammar-wrapped identifiers */
        $sql = implode(', ', $selects);

        return $builder->getModel()->newQueryWithoutScopes()
            ->fromSub($perRow, self::PER_ROW_ALIAS)
            ->selectRaw($sql);
    }

    private function rollup(string $function, string $column, Grammar $grammar): string
    {
        return sprintf('%s(%s) AS %s', $function, $grammar->wrap(self::PER_ROW_ALIAS.'.'.$column), $grammar->wrap($column));
    }

    /**
     * Aggregate expressions for a single-row group. AVG is split into SUM and COUNT so averages
     * are always a global SUM / COUNT; EXISTS is a COUNT tested for > 0.
     *
     * @param  array<string, string|array{sum: string, count: string}>  $existsCols
     * @return literal-string
     */
    private function aggregateSelects(InstructionGroup $instructionGroup, Grammar $grammar, array &$existsCols): string
    {
        $selects = [];

        foreach ($instructionGroup->instructions as $instruction) {
            $alias = $instruction->alias;
            $wrappedCol = $this->wrapColumn($instruction->column, $grammar);

            if ($instruction->function === 'exists') {
                $cntCol = '_agg_ecnt_'.$alias;
                $selects[] = sprintf('COUNT(*) AS %s', $grammar->wrap($cntCol));
                $existsCols[$alias] = $cntCol;
            } elseif ($instruction->function === 'avg') {
                $sumCol = '_agg_sum_'.$alias;
                $cntCol = '_agg_cnt_'.$alias;
                $selects[] = sprintf('SUM(%s) AS %s', $wrappedCol, $grammar->wrap($sumCol));
                $selects[] = sprintf('COUNT(%s) AS %s', $wrappedCol, $grammar->wrap($cntCol));
                $existsCols[$alias] = ['sum' => $sumCol, 'count' => $cntCol];
            } else {
                $selects[] = sprintf('%s(%s) AS %s', strtoupper($instruction->function), $wrappedCol, $grammar->wrap($alias));
            }
        }

        /** @var literal-string $sql aggregate expressions over grammar-wrapped identifiers */
        $sql = implode(', ', $selects);

        return $sql;
    }

    /**
     * Non-HasOneOrMany relations (BelongsTo, BelongsToMany, HasManyThrough, morphs, ...):
     * use correlated withAggregate() subqueries, one value per base row.
     *
     * AVG is split into correlated SUM and COUNT columns so the summarising query can
     * compute a global SUM / COUNT instead of an average of per-row averages.
     *
     * @param  array<string, string|array{sum: string, count: string}>  $existsCols
     * @param  Builder<*>  $builder
     */
    private function buildNonHasOneOrManyGroup(
        InstructionGroup $instructionGroup,
        Builder $builder,
        array &$existsCols,
    ): void {
        foreach ($instructionGroup->instructions as $instruction) {
            if ($instruction->function === 'avg') {
                $sumCol = '_agg_sum_'.$instruction->alias;
                $cntCol = '_agg_cnt_'.$instruction->alias;

                $builder->withAggregate($this->aliasedRelation($instructionGroup, $sumCol), $instruction->column ?? '*', 'sum');
                $builder->withAggregate($this->aliasedRelation($instructionGroup, $cntCol), $instruction->column ?? '*', 'count');

                $existsCols[$instruction->alias] = ['sum' => $sumCol, 'count' => $cntCol];

                continue;
            }

            if ($instruction->function === 'exists') {
                // EXISTS yields a boolean column on some drivers (e.g. PostgreSQL has no MAX(boolean)),
                // so count matching rows instead and test the rolled-up count for > 0.
                $cntCol = '_agg_ecnt_'.$instruction->alias;

                $builder->withAggregate($this->aliasedRelation($instructionGroup, $cntCol), '*', 'count');

                $existsCols[$instruction->alias] = $cntCol;

                continue;
            }

            $relations = $instruction->relations;

            if ($instructionGroup->constraints instanceof Closure) {
                // Pass the full $relations array so withAggregate receives the closure-keyed relation name.
                $builder->withAggregate($relations ?? [], $instruction->column ?? '*', $instruction->function);
            } else {
                // No constraint closure: pass just the relation name string (array_key_first) so
                // withAggregate does not try to interpret it as a closure-constrained relation.
                $builder->withAggregate((string) array_key_first($relations ?? []), $instruction->column ?? '*', $instruction->function);
            }
        }
    }

    /**
     * Build a withAggregate() relation argument that carries an explicit alias and the group constraint.
     *
     * @return string|array<string, Closure>
     */
    private function aliasedRelation(InstructionGroup $instructionGroup, string $alias): string|array
    {
        $name = sprintf('%s as %s', $instructionGroup->baseName, $alias);

        return $instructionGroup->constraints instanceof Closure
            ? [$name => $instructionGroup->constraints]
            : $name;
    }

    /** @param array<string, int> $counters */
    private function joinAlias(string $baseName, array &$counters): string
    {
        $count = ($counters[$baseName] = ($counters[$baseName] ?? 0) + 1);

        return $count === 1 ? 'agg_'.$baseName : 'agg_'.$baseName.'_'.$count;
    }

    private function wrapColumn(Expression|string|null $column, Grammar $grammar): string
    {
        if ($column instanceof Expression) {
            return (string) $column->getValue($grammar);
        }

        $raw = $column ?? '*';

        return $raw === '*' ? '*' : $grammar->wrap($raw);
    }
}
