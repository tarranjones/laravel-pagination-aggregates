<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\ServiceProvider;

class PaginationAggregatesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Builder::macro('paginateWithTotals', function (
            int|null $perPage = null,
            array $columns = ['*'],
            string $pageName = 'page',
            int|null $page = null,
        ): AggregateLengthAwarePaginator {
            return PaginatorFactory::paginate($this, $perPage, $columns, $pageName, $page);
        });

        Builder::macro('simplePaginateWithTotals', function (
            int|null $perPage = null,
            array $columns = ['*'],
            string $pageName = 'page',
            int|null $page = null,
        ): AggregatePaginator {
            return PaginatorFactory::simplePaginate($this, $perPage, $columns, $pageName, $page);
        });

        Builder::macro('cursorPaginateWithTotals', function (
            int|null $perPage = null,
            array $columns = ['*'],
            string $cursorName = 'cursor',
            Cursor|string|null $cursor = null,
        ): AggregateCursorPaginator {
            return PaginatorFactory::cursorPaginate($this, $perPage, $columns, $cursorName, $cursor);
        });
    }
}
