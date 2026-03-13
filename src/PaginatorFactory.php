<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;

class PaginatorFactory
{
    public static function paginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): AggregateLengthAwarePaginator {
        return new AggregateLengthAwarePaginator($builder, $perPage, $columns, $pageName, $page);
    }

    public static function simplePaginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): AggregatePaginator {
        return new AggregatePaginator($builder, $perPage, $columns, $pageName, $page);
    }

    public static function cursorPaginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $cursorName = 'cursor',
        Cursor|string|null $cursor = null,
    ): AggregateCursorPaginator {
        return new AggregateCursorPaginator($builder, $perPage, $columns, $cursorName, $cursor);
    }
}
