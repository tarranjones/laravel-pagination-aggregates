<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Pagination\CursorPaginator as BaseCursorPaginator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

/**
 * Laravel's CursorPaginator with an `aggregates` payload.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends BaseCursorPaginator<TKey, TValue>
 */
class CursorPaginator extends BaseCursorPaginator
{
    use AggregatesPaginator;
}
