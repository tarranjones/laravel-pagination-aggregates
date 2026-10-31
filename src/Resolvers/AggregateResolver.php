<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Grammars\Grammar;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;
use TarranJones\LaravelPaginationAggregates\AliasResolver;

class AggregateResolver
{
    /**
     * Alias for the number of base-query rows seen by the summarising query.
     */
    private const string ROW_COUNT_ALIAS = '_agg_row_count';

    /**
     * @param  AggregateInstruction[]  $instructions
     * @return array<string, mixed>
     */
    public function resolve(array $instructions, Builder $builder): array
    {
        if ($instructions === []) {
            return [];
        }

        // Optimization: if only one instruction and it's a base query aggregate,
        // use scalar query instead of derived table
        if (count($instructions) === 1 && $instructions[0]->relations === null) {
            return $this->resolveSingleInstruction($instructions[0], $builder);
        }

        $grammar = $builder->getQuery()->getGrammar();
        $groups = $this->groupInstructions($instructions, $builder);

        [$query, $existsCols] = (new JoinQueryBuilder)->build($groups, $builder, $grammar);

        $row = $this->summarise($query, $instructions, $existsCols, $grammar);

        if ((int) ($row->{self::ROW_COUNT_ALIAS} ?? 0) === 0) {
            $meta = [];
            foreach ($instructions as $instruction) {
                $meta[$instruction->alias] = match ($instruction->function) {
                    'count', 'sum' => 0,
                    default => null,
                };
            }

            return $meta;
        }

        return $this->aggregateResults($row, $instructions, $existsCols);
    }

    /**
     * Collapse the per-parent-row join query into a single row inside the database.
     *
     * The join query yields one row per base-query row; wrapping it in a derived table and
     * aggregating there means only one row ever reaches PHP, however large the result set.
     * Relation values are rolled up (SUM/MAX/MIN) across parents; base values are identical
     * on every row, so MAX() simply picks that value.
     *
     * @param  AggregateInstruction[]  $instructions
     * @param  array<string, string|array{sum: string, count: string}>  $existsCols
     */
    private function summarise(Builder $query, array $instructions, array $existsCols, Grammar $grammar): object
    {
        $query->reorder();

        $selects = ['COUNT(*) AS '.$grammar->wrap(self::ROW_COUNT_ALIAS)];

        foreach ($instructions as $instruction) {
            $isBase = $instruction->relations === null;
            $colInfo = $existsCols[$instruction->alias] ?? null;

            if ($colInfo === null) {
                $fn = match (true) {
                    $isBase => 'MAX',
                    in_array($instruction->function, ['count', 'sum'], true) => 'SUM',
                    $instruction->function === 'min' => 'MIN',
                    default => 'MAX',
                };

                $selects[] = $this->outerAggregate($fn, $instruction->alias, $grammar);
            } elseif (is_string($colInfo)) {
                $selects[] = $this->outerAggregate('MAX', $colInfo, $grammar);
            } else {
                $fn = $isBase ? 'MAX' : 'SUM';
                $selects[] = $this->outerAggregate($fn, $colInfo['sum'], $grammar);
                $selects[] = $this->outerAggregate($fn, $colInfo['count'], $grammar);
            }
        }

        return $query->getQuery()->newQuery()
            ->fromSub($query, 'agg_rows')
            ->selectRaw(implode(', ', $selects))
            ->first() ?? (object) [];
    }

    private function outerAggregate(string $function, string $column, Grammar $grammar): string
    {
        return sprintf('%s(%s) AS %s', $function, $grammar->wrap('agg_rows.'.$column), $grammar->wrap($column));
    }

    /**
     * Resolve a single base query aggregate instruction using scalar query methods.
     *
     * This is more efficient than building a derived table for just one aggregate.
     * Only called for base query aggregates (relations === null).
     *
     * @return array<string, mixed>
     */
    private function resolveSingleInstruction(AggregateInstruction $aggregateInstruction, Builder $builder): array
    {
        $alias = $aggregateInstruction->alias;
        $column = $aggregateInstruction->column;

        $query = clone $builder;

        if ($aggregateInstruction->constraint instanceof Closure) {
            ($aggregateInstruction->constraint)($query);
        }

        $value = match ($aggregateInstruction->function) {
            'count' => $query->count($column),
            'max' => $query->max($column),
            'min' => $query->min($column),
            'sum' => $query->sum($column),
            'avg' => $query->avg($column),
            'exists' => $query->exists(),
        };

        return [$alias => self::normalizeValue($aggregateInstruction->function, $value)];
    }

    /**
     * Group instructions by their query source and constraints.
     *
     * - Base query instructions (relations === null) are grouped by constraint key so that
     *   instructions sharing the same constraint can be batched into one CROSS JOIN subquery.
     * - Relation instructions are grouped by relation name + serialized constraints.
     *
     * @param  AggregateInstruction[]  $instructions
     * @return InstructionGroup[]
     */
    private function groupInstructions(array $instructions, Builder $builder): array
    {
        /** @var array<string, array{meta: array<string, mixed>, instructions: AggregateInstruction[]}> */
        $baseAccum = [];
        /** @var array<string, array{meta: array<string, mixed>, instructions: AggregateInstruction[]}> */
        $relAccum = [];

        /** @var array<string, Relation> */
        $relationCache = [];

        foreach ($instructions as $instruction) {
            if ($instruction->relations === null) {
                $key = 'base:'.$this->constraintKey($instruction->constraint, clone $builder);

                $baseAccum[$key] ??= [
                    'meta' => [
                        'type' => 'base',
                        'baseName' => null,
                        'constraints' => $instruction->constraint,
                        'table' => $builder->getModel()->getTable(),
                        'fk' => null,
                        'localKey' => null,
                        'isHasOneOrMany' => true,
                        'relation' => null,
                    ],
                    'instructions' => [],
                ];

                $baseAccum[$key]['instructions'][] = $instruction;

                continue;
            }

            $name = (string) array_key_first($instruction->relations);
            $constraints = $instruction->relations[$name];
            $baseName = AliasResolver::stripAlias($name);
            $relation = $relationCache[$baseName] ??= $builder->getModel()->newInstance()->{$baseName}();
            $isHasOneOrMany = $relation instanceof HasOneOrMany;

            $key = $baseName.':'.$this->relationConstraintKey($constraints, $relation);

            $relAccum[$key] ??= [
                'meta' => [
                    'type' => 'relation',
                    'baseName' => $baseName,
                    'constraints' => $constraints,
                    'table' => $relation->getRelated()->getTable(),
                    'fk' => $isHasOneOrMany ? $relation->getForeignKeyName() : null,
                    'localKey' => $isHasOneOrMany ? $builder->getModel()->getQualifiedKeyName() : null,
                    'isHasOneOrMany' => $isHasOneOrMany,
                    'relation' => $relation,
                ],
                'instructions' => [],
            ];

            $relAccum[$key]['instructions'][] = $instruction;
        }

        return array_map(
            $this->makeGroup(...),
            [...array_values($baseAccum), ...array_values($relAccum)],
        );
    }

    /**
     * @param  array{meta: array<string, mixed>, instructions: AggregateInstruction[]}  $item
     */
    private function makeGroup(array $item): InstructionGroup
    {
        $meta = $item['meta'];

        return new InstructionGroup(
            type: $meta['type'],
            baseName: $meta['baseName'],
            constraints: $meta['constraints'],
            table: $meta['table'],
            fk: $meta['fk'],
            localKey: $meta['localKey'],
            instructions: $item['instructions'],
            isHasOneOrMany: $meta['isHasOneOrMany'],
            relation: $meta['relation'],
        );
    }

    private function constraintKey(?Closure $constraint, Builder $builder): string
    {
        if (! $constraint instanceof Closure) {
            return 'none';
        }

        $constraint($builder);

        return md5($builder->toSql().serialize($builder->getBindings()));
    }

    private function relationConstraintKey(mixed $constraints, mixed $relation): string
    {
        if ($constraints === null) {
            return 'none';
        }

        $tempQuery = $relation->getRelated()->newQuery();
        $constraints($tempQuery);

        return md5($tempQuery->toSql().serialize($tempQuery->getBindings()));
    }

    /**
     * Map the summarised row onto the final metadata array.
     *
     * @param  AggregateInstruction[]  $instructions
     * @param  array<string, string|array{sum: string, count: string}>  $existsCols
     * @return array<string, mixed>
     */
    private function aggregateResults(object $row, array $instructions, array $existsCols): array
    {
        $meta = [];

        foreach ($instructions as $instruction) {
            $meta[$instruction->alias] = $this->resolveResult($instruction, $row, $existsCols[$instruction->alias] ?? null);
        }

        return $meta;
    }

    /**
     * @param  string|array{sum: string, count: string}|null  $colInfo
     */
    private function resolveResult(AggregateInstruction $aggregateInstruction, object $row, string|array|null $colInfo): mixed
    {
        if ($colInfo === null) {
            return self::normalizeValue($aggregateInstruction->function, $row->{$aggregateInstruction->alias} ?? null);
        }

        if (is_string($colInfo)) {
            // EXISTS via COUNT(*) column
            return (int) ($row->{$colInfo} ?? 0) > 0;
        }

        // AVG: global SUM / COUNT, never an average of averages
        $sum = $row->{$colInfo['sum']} ?? null;
        $count = (int) ($row->{$colInfo['count']} ?? 0);

        return $count > 0 ? (float) $sum / $count : null;
    }

    /**
     * Give every driver the same result types: drivers such as MySQL and PostgreSQL return
     * SUM() / AVG() as numeric strings, while SQLite returns native numbers.
     *
     * count → int, exists → bool, sum → int|float (0 when empty), avg → float|null.
     * max / min are returned as-is because they may be dates or strings.
     */
    public static function normalizeValue(string $function, mixed $value): mixed
    {
        return match ($function) {
            'count' => (int) ($value ?? 0),
            'exists' => (bool) $value,
            'sum' => self::toNumber($value ?? 0),
            'avg' => $value === null ? null : (float) $value,
            default => $value,
        };
    }

    private static function toNumber(mixed $value): mixed
    {
        if (! is_string($value) || ! is_numeric($value)) {
            return $value;
        }

        return str_contains($value, '.') || stripos($value, 'e') !== false
            ? (float) $value
            : (int) $value;
    }
}
