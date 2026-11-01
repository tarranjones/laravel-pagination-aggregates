<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Pagination\LengthAwarePaginator as BaseLengthAwarePaginator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

/**
 * Laravel's LengthAwarePaginator with an `aggregates` payload.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends BaseLengthAwarePaginator<TKey, TValue>
 */
class LengthAwarePaginator extends BaseLengthAwarePaginator
{
    use AggregatesPaginator;
}
