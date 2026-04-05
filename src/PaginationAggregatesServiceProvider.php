<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\ServiceProvider;
use TarranJones\LaravelPaginationAggregates\Pagination\CursorPaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\LengthAwarePaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\Paginator;

class PaginationAggregatesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Builder::macro('lazyPaginate', fn (?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null, ?int $total = null): LengthAwarePaginator => PaginatorFactory::paginate($this, $perPage, $columns, $pageName, $page, $total));

        Builder::macro('lazySimplePaginate', fn (?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): Paginator => PaginatorFactory::simplePaginate($this, $perPage, $columns, $pageName, $page));

        Builder::macro('lazyCursorPaginate', fn (?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator => PaginatorFactory::cursorPaginate($this, $perPage, $columns, $cursorName, $cursor));
    }
}
