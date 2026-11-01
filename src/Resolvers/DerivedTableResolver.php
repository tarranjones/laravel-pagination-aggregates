<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;

/**
 * Resolves base-query aggregates for grouped, HAVING, DISTINCT and UNION queries.
 *
 * Those queries return one row per group / distinct value / union row, so the base query is
 * wrapped in a derived table and aggregated over its result rows — the same rows the paginator
 * returns across all pages, and the same approach Laravel uses to count their total.
 */
class DerivedTableResolver
{
    private const string TABLE_ALIAS = 'agg_base';

    /**
     * Whether the base query must be wrapped in a derived table to aggregate its result rows.
     *
     * @param  Builder<*>  $builder
     */
    public static function requiresDerivedTable(Builder $builder): bool
    {
        $query = $builder->getQuery();

        return ! empty($query->groups)
            || ! empty($query->havings)
            || ! empty($query->unions)
            || $query->distinct !== false;
    }

    /**
     * @param  AggregateInstruction[]  $instructions  base-query instructions only
     * @param  Builder<*>  $builder
     * @return array<string, mixed>
     */
    public function resolve(array $instructions, Builder $builder): array
    {
        $grammar = $builder->getQuery()->getGrammar();

        /** @var array<string, array{constraint: Closure|null, instructions: AggregateInstruction[]}> $batches */
        $batches = [];

        foreach ($instructions as $instruction) {
            $key = AggregateResolver::constraintKey($instruction->constraint, clone $builder);

            $batches[$key] ??= ['constraint' => $instruction->constraint, 'instructions' => []];
            $batches[$key]['instructions'][] = $instruction;
        }

        $meta = [];

        foreach ($batches as $batch) {
            $meta += $this->resolveBatch($batch['instructions'], $batch['constraint'], $builder, $grammar);
        }

        return $meta;
    }

    /**
     * @param  AggregateInstruction[]  $instructions
     * @param  Builder<*>  $builder
     * @return array<string, mixed>
     */
    private function resolveBatch(array $instructions, ?Closure $constraint, Builder $builder, Grammar $grammar): array
    {
        $subQuery = clone $builder;

        if ($constraint instanceof Closure) {
            $constraint($subQuery);
        }

        // ORDER BY is meaningless inside the derived table and rejected by some drivers.
        $subQuery->reorder();

        $selects = array_map(
            fn (AggregateInstruction $instruction): string => sprintf(
                '%s AS %s',
                $this->aggregateExpression($instruction, $grammar),
                $grammar->wrap($instruction->alias),
            ),
            $instructions,
        );

        $row = $builder->getQuery()->newQuery()
            ->fromSub($subQuery, self::TABLE_ALIAS)
            ->selectRaw(implode(', ', $selects))
            ->first() ?? (object) [];

        $meta = [];

        foreach ($instructions as $instruction) {
            $value = $row->{$instruction->alias} ?? null;

            $meta[$instruction->alias] = $instruction->function === 'exists'
                ? (int) ($value ?? 0) > 0
                : AggregateResolver::normalizeValue($instruction->function, $value);
        }

        return $meta;
    }

    private function aggregateExpression(AggregateInstruction $aggregateInstruction, Grammar $grammar): string
    {
        $column = $aggregateInstruction->column;

        if ($aggregateInstruction->function === 'exists' || $column === null || $column === '*') {
            return 'COUNT(*)';
        }

        return sprintf('%s(%s)', strtoupper($aggregateInstruction->function), $this->resultColumn($column, $grammar));
    }

    /**
     * Reference a column of the derived table. Qualified names (`orders.quantity`) are reduced to
     * the result column name, since the derived table exposes the base query's selected columns.
     */
    private function resultColumn(Expression|string $column, Grammar $grammar): string
    {
        if ($column instanceof Expression) {
            return (string) $column->getValue($grammar);
        }

        $segments = explode('.', $column);

        return $grammar->wrap(self::TABLE_ALIAS.'.'.end($segments));
    }
}
