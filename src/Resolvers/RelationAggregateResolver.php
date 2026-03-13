<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;

class RelationAggregateResolver
{
    /**
     * @param  AggregateInstruction[]  $instructions
     * @return array<string, mixed>
     */
    public function resolve(array $instructions, Builder $builder): array
    {
        $query = clone $builder;
        $query->select($query->getModel()->getQualifiedKeyName());

        // For avg, we compute sum+count per model to derive the true global average,
        // avoiding the incorrect average-of-averages result.
        $avgInternalAliases = [];

        foreach ($instructions as $instruction) {
            if ($instruction->function === 'avg') {
                $baseRelation = is_string($instruction->relations)
                    ? trim(preg_split('/\s+as\s+/i', $instruction->relations, 2)[0])
                    : $instruction->relations;

                $sumAttr = $instruction->alias.'__sum';
                $countAttr = $instruction->alias.'__cnt';

                $sumRelation = is_string($baseRelation) ? $baseRelation.' as '.$sumAttr : $baseRelation;
                $countRelation = is_string($baseRelation) ? $baseRelation.' as '.$countAttr : $baseRelation;

                $query->withAggregate($sumRelation, $instruction->column, 'sum');
                $query->withAggregate($countRelation, $instruction->column, 'count');

                $avgInternalAliases[$instruction->alias] = [$sumAttr, $countAttr];
            } else {
                $query->withAggregate($instruction->relations, $instruction->column, $instruction->function);
            }
        }

        $results = $query->get();
        $meta = [];

        foreach ($instructions as $instruction) {
            $alias = $instruction->alias;

            if ($results->isEmpty()) {
                $meta[$alias] = match ($instruction->function) {
                    'count', 'sum' => 0,
                    default => null,
                };

                continue;
            }

            if ($instruction->function === 'avg') {
                [$sumAttr, $countAttr] = $avgInternalAliases[$alias];
                $totalSum = $results->sum(fn ($m): float => (float) ($m->getAttribute($sumAttr) ?? 0));
                $totalCount = $results->sum(fn ($m): int => (int) ($m->getAttribute($countAttr) ?? 0));
                $meta[$alias] = $totalCount > 0 ? $totalSum / $totalCount : null;
            } else {
                $values = $results->map(fn ($m) => $m->getAttribute($alias));
                $meta[$alias] = match ($instruction->function) {
                    'count', 'sum' => $values->sum(),
                    'max' => $values->max(),
                    'min' => $values->min(),
                    'exists' => (bool) $values->filter()->count(),
                    default => null,
                };
            }
        }

        return $meta;
    }
}
