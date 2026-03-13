<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;

readonly class AggregateInstruction
{
    public function __construct(
        /** @var 'direct'|'page'|'relation' */
        public string $type,
        /** @var 'count'|'max'|'min'|'sum'|'avg'|'exists' */
        public string $function,
        public string $alias,
        public Expression|string|null $column,
        public string|array|null $relations,
        public ?Closure $callback,
    ) {}
}
