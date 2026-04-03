<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;

class JoinQueryBuilder
{
    /**
     * Build the derived-table JOIN query.
     *
     * @param  InstructionGroup[]  $groups
     * @return array{0: Builder, 1: array<string, string|array>}
     */
    public function build(array $groups, Builder $builder, Grammar $grammar): array
    {
        $query = clone $builder;
        $query->select($query->getModel()->getQualifiedKeyName());

        $existsCols = [];
        $joinAliasCounters = [];

        foreach ($groups as $group) {
            if ($group->type === 'base') {
                $this->buildBaseGroup($group, $builder, $query, $grammar, $joinAliasCounters, $existsCols);
            } elseif ($group->type === 'relation' && ! $group->isHasOneOrMany) {
                $this->buildNonHasOneOrManyGroup($group, $query, $existsCols);
            } else {
                $this->buildHasOneOrManyGroup($group, $query, $grammar, $joinAliasCounters, $existsCols);
            }
        }

        return [$query, $existsCols];
    }

    /**
     * Base query aggregates: CROSS JOIN with a subquery that computes aggregates
     * over the filtered dataset, adding those values to every row.
     *
     * $builder is the original unmodified builder (used as the base for the subquery).
     * $query is the accumulating query that receives the CROSS JOIN and SELECT additions.
     *
     * @param  array<string, int>  $joinAliasCounters
     * @param  array<string, mixed>  $existsCols
     */
    private function buildBaseGroup(
        InstructionGroup $instructionGroup,
        Builder $builder,
        Builder $query,
        Grammar $grammar,
        array &$joinAliasCounters,
        array &$existsCols,
    ): void {
        $jAlias = $this->joinAlias($instructionGroup->table, $joinAliasCounters);
        $subSelects = [];

        foreach ($instructionGroup->instructions as $instruction) {
            $alias = $instruction->alias;
            $wrappedCol = $this->wrapColumn($instruction->column, $grammar);

            if ($instruction->function === 'exists') {
                $subSelects[] = sprintf('(CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END) AS %s', $grammar->wrap($alias));
                $existsCols[$alias] = '_pag_base_exists';
                $query->addSelect(sprintf('%s.%s', $jAlias, $alias));
            } elseif ($instruction->function === 'avg') {
                $sumCol = '_pag_sum_'.$alias;
                $cntCol = '_pag_cnt_'.$alias;
                $subSelects[] = sprintf('SUM(%s) AS %s', $wrappedCol, $grammar->wrap($sumCol));
                $subSelects[] = sprintf('COUNT(%s) AS %s', $wrappedCol, $grammar->wrap($cntCol));
                $existsCols[$alias] = ['sum' => $sumCol, 'count' => $cntCol];
                $query->addSelect(sprintf('%s.%s', $jAlias, $sumCol));
                $query->addSelect(sprintf('%s.%s', $jAlias, $cntCol));
            } else {
                $fn = strtoupper($instruction->function);
                $subSelects[] = sprintf('%s(%s) AS %s', $fn, $wrappedCol, $grammar->wrap($alias));
                $query->addSelect(sprintf('%s.%s', $jAlias, $alias));
            }
        }

        $subQuery = clone $builder;
        $subQuery->getQuery()->columns = null;

        if ($instructionGroup->constraints instanceof Closure) {
            ($instructionGroup->constraints)($subQuery);
        }

        $subQuery->selectRaw(implode(', ', $subSelects));
        $query->crossJoinSub($subQuery, $jAlias);
    }

    /**
     * Non-HasOneOrMany relations (e.g. BelongsToMany): use correlated withAggregate()
     * subqueries. AVG is deferred to post-processing in AggregateResolver.
     *
     * @param  array<string, mixed>  $existsCols
     */
    private function buildNonHasOneOrManyGroup(
        InstructionGroup $instructionGroup,
        Builder $builder,
        array &$existsCols,
    ): void {
        foreach ($instructionGroup->instructions as $instruction) {
            if ($instruction->function === 'avg') {
                $existsCols[$instruction->alias] = [
                    'type' => 'avg_non_has_one_or_many',
                    'relation' => $instructionGroup->relation,
                    'constraints' => $instructionGroup->constraints,
                    'column' => $instruction->column,
                ];

                continue;
            }

            $relations = $instruction->relations;

            if ($instructionGroup->constraints instanceof Closure) {
                // Pass the full $relations array so withAggregate receives the closure-keyed relation name.
                $builder->withAggregate($relations, $instruction->column, $instruction->function);
            } else {
                // No constraint closure: pass just the relation name string (array_key_first) so
                // withAggregate does not try to interpret it as a closure-constrained relation.
                $builder->withAggregate(array_key_first($relations), $instruction->column, $instruction->function);
            }
        }
    }

    /**
     * HasOneOrMany relations: GROUP BY subquery pre-aggregated by FK, then LEFT JOIN.
     *
     * @param  array<string, int>  $joinAliasCounters
     * @param  array<string, mixed>  $existsCols
     */
    private function buildHasOneOrManyGroup(
        InstructionGroup $instructionGroup,
        Builder $builder,
        Grammar $grammar,
        array &$joinAliasCounters,
        array &$existsCols,
    ): void {
        $jAlias = $this->joinAlias($instructionGroup->baseName, $joinAliasCounters);
        $fk = $instructionGroup->fk;
        $localKey = $instructionGroup->localKey;
        $subSelects = [$fk];
        $avgCols = [];

        foreach ($instructionGroup->instructions as $instruction) {
            $alias = $instruction->alias;
            $wrappedCol = $this->wrapColumn($instruction->column, $grammar);

            if ($instruction->function === 'exists') {
                $cntCol = '_pag_ecnt_'.$alias;
                $subSelects[] = sprintf('COUNT(*) AS %s', $grammar->wrap($cntCol));
                $existsCols[$alias] = $cntCol;
                $builder->addSelect(sprintf('%s.%s', $jAlias, $cntCol));
            } elseif ($instruction->function === 'avg') {
                $sumCol = '_pag_sum_'.$alias;
                $cntCol = '_pag_cnt_'.$alias;
                $subSelects[] = sprintf('SUM(%s) AS %s', $wrappedCol, $grammar->wrap($sumCol));
                $subSelects[] = sprintf('COUNT(%s) AS %s', $wrappedCol, $grammar->wrap($cntCol));
                $avgCols[$alias] = ['sum' => $sumCol, 'count' => $cntCol];
                $builder->addSelect(sprintf('%s.%s', $jAlias, $sumCol));
                $builder->addSelect(sprintf('%s.%s', $jAlias, $cntCol));
            } else {
                $fn = strtoupper($instruction->function);
                $subSelects[] = sprintf('%s(%s) AS %s', $fn, $wrappedCol, $grammar->wrap($alias));
                $builder->addSelect(sprintf('%s.%s', $jAlias, $alias));
            }
        }

        foreach ($avgCols as $alias => $cols) {
            $existsCols[$alias] = $cols;
        }

        $subQuery = $instructionGroup->relation->getRelated()->newQuery();

        if ($instructionGroup->constraints instanceof Closure) {
            ($instructionGroup->constraints)($subQuery);
        }

        $subQuery->selectRaw(implode(', ', $subSelects))->groupBy($fk);

        $builder->leftJoinSub($subQuery, $jAlias, sprintf('%s.%s', $jAlias, $fk), '=', $localKey);
    }

    /** @param array<string, int> $counters */
    private function joinAlias(string $baseName, array &$counters): string
    {
        $count = ($counters[$baseName] = ($counters[$baseName] ?? 0) + 1);

        return $count === 1 ? 'pag_'.$baseName : 'pag_'.$baseName.'_'.$count;
    }

    private function wrapColumn(Expression|string|null $column, Grammar $grammar): string
    {
        $raw = (string) ($column ?? '*');

        return $raw === '*' ? '*' : $grammar->wrap($raw);
    }
}
