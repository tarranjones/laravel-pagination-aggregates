<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use TarranJones\LaravelPaginationAggregates\Pagination\CursorPaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\LengthAwarePaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\Paginator;

class PaginatorFactory
{
    public static function paginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
        ?int $total = null,
    ): LengthAwarePaginator {
        return new LengthAwarePaginator($builder, $perPage, $columns, $pageName, $page, $total);
    }

    public static function simplePaginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): Paginator {
        return new Paginator($builder, $perPage, $columns, $pageName, $page);
    }

    public static function cursorPaginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $cursorName = 'cursor',
        Cursor|string|null $cursor = null,
    ): CursorPaginator {
        return new CursorPaginator($builder, $perPage, $columns, $cursorName, $cursor);
    }
}
