<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Illuminate\Database\Eloquent\Builder;
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
            // ─── Base query aggregates: use subquery with CROSS JOIN ───────
            //
            // Base query aggregates use a subquery that computes aggregates over the
            // filtered dataset, then CROSS JOIN to add those values to every row
            if ($group->type === 'base') {
                $jAlias = $this->joinAlias('base', $joinAliasCounters);
                $subSelects = [];

                foreach ($group->instructions as $instruction) {
                    $alias = $instruction->alias;
                    $colWithoutAlias = (string) ($instruction->column ?? '*');
                    $wrappedCol = $colWithoutAlias === '*' ? '*' : $grammar->wrap($colWithoutAlias);

                    if ($instruction->function === 'exists') {
                        // EXISTS: check if any rows exist
                        $subSelects[] = sprintf('(CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END) AS %s', $grammar->wrap($alias));
                        $existsCols[$alias] = 'base_exists';
                        $query->addSelect(sprintf('%s.%s', $jAlias, $alias));
                    } elseif ($instruction->function === 'avg') {
                        // AVG: use SUM/COUNT for proper global average
                        $sumCol = $alias.'__sum';
                        $cntCol = $alias.'__cnt';
                        $subSelects[] = sprintf('SUM(%s) AS %s', $wrappedCol, $grammar->wrap($sumCol));
                        $subSelects[] = sprintf('COUNT(%s) AS %s', $wrappedCol, $grammar->wrap($cntCol));
                        $existsCols[$alias] = ['sum' => $sumCol, 'count' => $cntCol];
                        $query->addSelect(sprintf('%s.%s', $jAlias, $sumCol));
                        $query->addSelect(sprintf('%s.%s', $jAlias, $cntCol));
                    } else {
                        // COUNT, SUM, MAX, MIN
                        $fn = strtoupper($instruction->function);
                        $subSelects[] = sprintf('%s(%s) AS %s', $fn, $wrappedCol, $grammar->wrap($alias));
                        $query->addSelect(sprintf('%s.%s', $jAlias, $alias));
                    }
                }

                // Build base query subquery, applying any constraint closure
                $subQuery = clone $builder;
                $subQuery->getQuery()->columns = null; // Clear existing selects

                if ($group->constraints !== null) {
                    ($group->constraints)($subQuery);
                }

                $subQuery->selectRaw(implode(', ', $subSelects));

                // CROSS JOIN to add aggregates to every row
                $query->crossJoinSub($subQuery, $jAlias);

                continue;
            }

            // Non-HasOneOrMany relations use correlated subqueries via withAggregate
            // except for AVG which needs special handling
            if ($group->type === 'relation' && ! $group->isHasOneOrMany) {
                foreach ($group->instructions as $instruction) {
                    // Skip AVG for now, we'll handle it in aggregateResults using a direct query
                    if ($instruction->function === 'avg') {
                        // Mark this instruction for post-processing
                        $existsCols[$instruction->alias] = ['type' => 'avg_non_has_one_or_many', 'relation' => $group->relation, 'constraints' => $group->constraints, 'column' => $instruction->column];

                        continue;
                    }

                    // Build the relations array properly, filtering out null constraints
                    $relations = $instruction->relations;
                    if ($group->constraints !== null) {
                        // Relations array should have the constraint
                        $query->withAggregate($relations, $instruction->column, $instruction->function);
                    } else {
                        // No constraints, just pass the relation name
                        $relationName = array_key_first($relations);
                        $query->withAggregate($relationName, $instruction->column, $instruction->function);
                    }
                }

                continue;
            }

            // ─── Derived-table path for HasOneOrMany relations ───────
            //
            // Build a GROUP BY subquery that pre-aggregates by FK, then LEFT JOIN

            $jAlias = $this->joinAlias($group->baseName, $joinAliasCounters);
            $fk = $group->fk;
            $localKey = $group->localKey;

            $subSelects = [$fk]; // FK is the GROUP BY key

            // Track AVG instructions for special handling
            $avgCols = [];

            foreach ($group->instructions as $instruction) {
                $alias = $instruction->alias;
                $colWithoutAlias = (string) ($instruction->column ?? '*');
                $wrappedCol = $colWithoutAlias === '*' ? '*' : $grammar->wrap($colWithoutAlias);

                if ($instruction->function === 'exists') {
                    $cntCol = $alias.'__ecnt';
                    $subSelects[] = sprintf('COUNT(*) AS %s', $grammar->wrap($cntCol));
                    $existsCols[$alias] = $cntCol;
                    $query->addSelect(sprintf('%s.%s', $jAlias, $cntCol));
                } elseif ($instruction->function === 'avg') {
                    // For AVG, we need SUM and COUNT to compute global average
                    $sumCol = $alias.'__sum';
                    $cntCol = $alias.'__cnt';
                    $subSelects[] = sprintf('SUM(%s) AS %s', $wrappedCol, $grammar->wrap($sumCol));
                    $subSelects[] = sprintf('COUNT(%s) AS %s', $wrappedCol, $grammar->wrap($cntCol));
                    $avgCols[$alias] = ['sum' => $sumCol, 'count' => $cntCol];
                    $query->addSelect(sprintf('%s.%s', $jAlias, $sumCol));
                    $query->addSelect(sprintf('%s.%s', $jAlias, $cntCol));
                } else {
                    $fn = strtoupper($instruction->function);
                    $subSelects[] = sprintf('%s(%s) AS %s', $fn, $wrappedCol, $grammar->wrap($alias));
                    $query->addSelect(sprintf('%s.%s', $jAlias, $alias));
                }
            }

            // Store avgCols in existsCols for tracking
            foreach ($avgCols as $alias => $cols) {
                $existsCols[$alias] = $cols;
            }

            // Build the relation subquery
            $subQuery = $group->relation->getRelated()->newQuery();
            if ($group->constraints !== null) {
                ($group->constraints)($subQuery);
            }

            $subQuery->selectRaw(implode(', ', $subSelects))->groupBy($fk);

            $query->leftJoinSub($subQuery, $jAlias, sprintf('%s.%s', $jAlias, $fk), '=', $localKey);
        }

        return [$query, $existsCols];
    }

    /**
     * @param  array<string, mixed>  $counters
     */
    private function joinAlias(string $baseName, array &$counters): string
    {
        $count = ($counters[$baseName] = ($counters[$baseName] ?? 0) + 1);

        return $count === 1 ? 'pag_'.$baseName : 'pag_'.$baseName.'_'.$count;
    }
}
