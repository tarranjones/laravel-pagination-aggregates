<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;
use TarranJones\LaravelPaginationAggregates\AliasResolver;

class AggregateResolver
{
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

        $results = $query->get();

        if ($results->isEmpty()) {
            $meta = [];
            foreach ($instructions as $instruction) {
                $meta[$instruction->alias] = match ($instruction->function) {
                    'count', 'sum' => 0,
                    default => null,
                };
            }

            return $meta;
        }

        $meta = $this->aggregateResults($results, $instructions, $existsCols);

        // Handle AVG for non-HasOneOrMany relations with a single query per instruction
        foreach ($existsCols as $alias => $colInfo) {
            if (is_array($colInfo) && isset($colInfo['type']) && $colInfo['type'] === 'avg_non_has_one_or_many') {
                $meta[$alias] = $this->resolveNonHasOneOrManyAvg($colInfo['relation'], $colInfo['constraints'], $colInfo['column'], $builder);
            }
        }

        return $meta;
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

        return [$alias => $value];
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

        foreach ($instructions as $instruction) {
            if ($instruction->relations === null) {
                $key = 'base:'.$this->constraintKey($instruction->constraint, clone $builder);

                if (! isset($baseAccum[$key])) {
                    $baseAccum[$key] = [
                        'meta' => [
                            'type'           => 'base',
                            'baseName'       => null,
                            'constraints'    => $instruction->constraint,
                            'table'          => $builder->getModel()->getTable(),
                            'fk'             => null,
                            'localKey'       => null,
                            'isHasOneOrMany' => true,
                            'relation'       => null,
                        ],
                        'instructions' => [],
                    ];
                }

                $baseAccum[$key]['instructions'][] = $instruction;

                continue;
            }

            $name        = (string) array_key_first($instruction->relations);
            $constraints = $instruction->relations[$name];
            $baseName    = AliasResolver::stripAlias($name);
            $relation    = $builder->getModel()->newInstance()->{$baseName}();
            $isHasOneOrMany = $relation instanceof HasOneOrMany;

            $key = $baseName.':'.$this->relationConstraintKey($constraints, $relation);

            if (! isset($relAccum[$key])) {
                $relAccum[$key] = [
                    'meta' => [
                        'type'           => 'relation',
                        'baseName'       => $baseName,
                        'constraints'    => $constraints,
                        'table'          => $relation->getRelated()->getTable(),
                        'fk'             => $isHasOneOrMany ? $relation->getForeignKeyName() : null,
                        'localKey'       => $isHasOneOrMany ? $builder->getModel()->getQualifiedKeyName() : null,
                        'isHasOneOrMany' => $isHasOneOrMany,
                        'relation'       => $relation,
                    ],
                    'instructions' => [],
                ];
            }

            $relAccum[$key]['instructions'][] = $instruction;
        }

        $groups = [];

        foreach (array_reverse($baseAccum) as $item) {
            array_unshift($groups, new InstructionGroup(...$item['meta'], instructions: $item['instructions']));
        }

        foreach ($relAccum as $item) {
            $groups[] = new InstructionGroup(...$item['meta'], instructions: $item['instructions']);
        }

        return $groups;
    }

    private function constraintKey(?Closure $constraint, Builder $tempQuery): string
    {
        if ($constraint === null) {
            return 'none';
        }

        $constraint($tempQuery);

        return md5($tempQuery->toSql().serialize($tempQuery->getBindings()));
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
     * Aggregate join-query results into the final metadata array.
     *
     * @param  AggregateInstruction[]  $instructions
     * @param  array<string, string|array>  $existsCols
     * @return array<string, mixed>
     */
    private function aggregateResults(Collection $results, array $instructions, array $existsCols): array
    {
        $meta = [];

        foreach ($instructions as $instruction) {
            $alias = $instruction->alias;

            // Base query aggregates are directly in the SELECT, single value across all rows
            if ($instruction->relations === null) {
                if (isset($existsCols[$alias])) {
                    $colInfo = $existsCols[$alias];

                    if (is_array($colInfo)) {
                        // AVG: compute sum/count from first row (all rows have same aggregate values)
                        $sum = $results->first()->getAttribute($colInfo['sum']);
                        $count = $results->first()->getAttribute($colInfo['count']);
                        $meta[$alias] = $count > 0 ? $sum / $count : null;
                    } elseif ($colInfo === 'base_exists') {
                        // EXISTS: directly in the row as boolean
                        $meta[$alias] = (bool) ($results->first()->getAttribute($alias) ?? 0);
                    } else {
                        // Shouldn't reach here for base queries
                        $meta[$alias] = (bool) ($results->first()->getAttribute($colInfo) ?? 0);
                    }
                } else {
                    // Direct aggregate (COUNT, SUM, MAX, MIN) - same value in all rows
                    $meta[$alias] = $results->first()->getAttribute($alias);
                }

                continue;
            }

            // Relation aggregates need to be summed/max/min across all rows
            if (isset($existsCols[$alias])) {
                $colInfo = $existsCols[$alias];

                if (is_array($colInfo)) {
                    // Skip avg_non_has_one_or_many - handled separately
                    if (isset($colInfo['type']) && $colInfo['type'] === 'avg_non_has_one_or_many') {
                        continue;
                    }

                    // AVG: compute global sum/count
                    $totalSum = $results->sum($colInfo['sum']);
                    $totalCount = $results->sum($colInfo['count']);
                    $meta[$alias] = $totalCount > 0 ? $totalSum / $totalCount : null;
                } else {
                    // EXISTS
                    $meta[$alias] = (bool) $results->sum(fn ($m): int => (int) ($m->getAttribute($colInfo) ?? 0));
                }
            } else {
                $values = $results->pluck($alias);
                $meta[$alias] = match ($instruction->function) {
                    'count', 'sum' => $values->sum(),
                    'max' => $values->max(),
                    'min' => $values->min(),
                    'exists' => (bool) $values->filter()->count(), // fallback path only
                    default => null,
                };
            }
        }

        return $meta;
    }

    /**
     * Resolve AVG for non-HasOneOrMany relations (e.g., BelongsToMany).
     *
     * Uses getRelationExistenceQuery with the per-row whereColumn stripped so that
     * the query computes the global average across all parent IDs rather than a
     * correlated per-row result.
     */
    private function resolveNonHasOneOrManyAvg(mixed $relation, ?Closure $constraints, mixed $column, Builder $builder): mixed
    {
        $existenceQuery = $relation->getRelationExistenceQuery(
            $relation->getRelated()->newQuery(),
            $builder,
            new Expression('*'),
        );

        // Remove correlated whereColumn to make it a global aggregate
        $existenceQuery->getQuery()->wheres = array_values(array_filter(
            $existenceQuery->getQuery()->wheres,
            fn (array $where): bool => $where['type'] !== 'Column',
        ));

        if ($constraints instanceof Closure) {
            $constraints($existenceQuery);
        }

        $parentIds = (clone $builder)->select($builder->getModel()->getQualifiedKeyName());
        $qualifiedColumn = $relation->getRelated()->qualifyColumn((string) $column);

        return $existenceQuery
            ->whereIn($relation->getExistenceCompareKey(), $parentIds)
            ->avg($qualifiedColumn);
    }
}
