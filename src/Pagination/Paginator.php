<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Pagination\Paginator as BasePaginator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

/**
 * Laravel's Paginator with an `aggregates` payload.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends BasePaginator<TKey, TValue>
 */
class Paginator extends BasePaginator
{
    use AggregatesPaginator;
}
