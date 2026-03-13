<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Illuminate\Support\Collection;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;

class PageAggregateResolver
{
    /**
     * @param  AggregateInstruction[]  $instructions
     * @return array<string, mixed>
     */
    public function resolve(array $instructions, Collection $collection): array
    {
        $meta = [];

        foreach ($instructions as $instruction) {
            $col = $instruction->column;
            $alias = $instruction->alias;
            $items = $instruction->callback
                ? $collection->filter($instruction->callback)
                : $collection;

            $meta[$alias] = match ($instruction->function) {
                'count' => $items->count(),
                'max' => $items->max($col),
                'min' => $items->min($col),
                'sum' => $items->sum($col),
                'avg' => $items->avg($col),
                'exists' => $items->isNotEmpty(),
                default => null,
            };
        }

        return $meta;
    }
}
