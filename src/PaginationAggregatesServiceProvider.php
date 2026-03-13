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
        Builder::macro('paginateWithTotals', fn(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): AggregateLengthAwarePaginator => PaginatorFactory::paginate($this, $perPage, $columns, $pageName, $page));

        Builder::macro('simplePaginateWithTotals', fn(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): AggregatePaginator => PaginatorFactory::simplePaginate($this, $perPage, $columns, $pageName, $page));

        Builder::macro('cursorPaginateWithTotals', fn(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null): AggregateCursorPaginator => PaginatorFactory::cursorPaginate($this, $perPage, $columns, $cursorName, $cursor));
    }
}
