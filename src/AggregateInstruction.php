<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;

readonly class AggregateInstruction
{
    public function __construct(
        /** @var 'count'|'max'|'min'|'sum'|'avg'|'exists' */
        public string $function,
        public string $alias,
        public Expression|string|null $column,
        /** @var array<string, mixed>|null — keyed by relation name, null for base query aggregates */
        public ?array $relations,
        /** Constraint for direct (base-query) aggregates: a Closure modifies the query builder; an Enumerable is used as the dataset directly, skipping DB. */
        public Closure|Enumerable|null $constraint = null,
    ) {
        if (! in_array($function, ['count', 'max', 'min', 'sum', 'avg', 'exists'], true)) {
            throw new InvalidArgumentException(sprintf("Invalid aggregate function '%s'. Must be one of: count, max, min, sum, avg, exists.", $function));
        }

        if ($column === null && in_array($function, ['max', 'min', 'sum', 'avg'], true)) {
            throw new InvalidArgumentException(sprintf("Aggregate function '%s' requires a non-null column.", $function));
        }
    }
}
