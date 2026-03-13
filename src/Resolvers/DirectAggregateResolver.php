<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;

class DirectAggregateResolver
{
    /**
     * @param  AggregateInstruction[]  $instructions
     * @param  int|null  $total  Pre-computed total from LengthAwarePaginator, or null
     * @return array<string, mixed>
     */
    public function resolve(
        array $instructions,
        Builder $builder,
        ?int $total,
        bool $isSinglePage,
        Collection $collection,
    ): array {
        $meta = [];

        foreach ($instructions as $instruction) {
            $alias = $instruction->alias;
            $column = $instruction->column;

            if ($instruction->callback === null) {
                // LengthAwarePaginator: total already computed, no extra query needed
                if ($total !== null) {
                    if ($instruction->function === 'count' && $column === '*') {
                        $meta[$alias] = $total;

                        continue;
                    }
                    if ($instruction->function === 'exists') {
                        $meta[$alias] = $total > 0;

                        continue;
                    }
                }

                // Single page: all rows are in the collection, no query needed
                if ($isSinglePage) {
                    $meta[$alias] = match ($instruction->function) {
                        'count' => $collection->count(),
                        'max' => $collection->max($column),
                        'min' => $collection->min($column),
                        'sum' => $collection->sum($column),
                        'avg' => $collection->avg($column),
                        'exists' => $collection->isNotEmpty(),
                        default => null,
                    };

                    continue;
                }
            }

            $query = clone $builder;

            if ($instruction->callback !== null) {
                ($instruction->callback)($query);
            }

            $meta[$alias] = match ($instruction->function) {
                'count' => $query->count(),
                'max' => $query->max($column),
                'min' => $query->min($column),
                'sum' => $query->sum($column),
                'avg' => $query->avg($column),
                'exists' => $query->exists(),
                default => null,
            };
        }

        return $meta;
    }
}
