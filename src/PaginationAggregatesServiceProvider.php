<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Contracts\Database\Query\Expression;
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
        $this->registerAggregateMacros();
        $this->registerPaginationMacros();
    }

    /**
     * withAgg*() declare aggregates over the whole result set; paginate*WithAggregates() reads them.
     */
    private function registerAggregateMacros(): void
    {
        Builder::macro('withAggCount', function (string|array|null $relations = null): Builder {
            /** @var Builder<*> $this */
            PendingAggregates::for($this)->withAggCount(...func_get_args());

            return $this;
        });

        Builder::macro('withAggExists', function (string|array|null $relations = null): Builder {
            /** @var Builder<*> $this */
            PendingAggregates::for($this)->withAggExists(...func_get_args());

            return $this;
        });

        foreach (['withAggMax', 'withAggMin', 'withAggSum', 'withAggAvg'] as $method) {
            Builder::macro($method, function (Expression|string|array|null $relation = null, Expression|string|null $column = null) use ($method): Builder {
                /** @var Builder<*> $this */
                PendingAggregates::for($this)->{$method}($relation, $column);

                return $this;
            });
        }
    }

    private function registerPaginationMacros(): void
    {
        Builder::macro('paginateWithAggregates', fn (?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null, ?int $total = null): LengthAwarePaginator => PaginatorFactory::paginate($this, $perPage, $columns, $pageName, $page, $total));

        Builder::macro('simplePaginateWithAggregates', fn (?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): Paginator => PaginatorFactory::simplePaginate($this, $perPage, $columns, $pageName, $page));

        Builder::macro('cursorPaginateWithAggregates', fn (?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator => PaginatorFactory::cursorPaginate($this, $perPage, $columns, $cursorName, $cursor));
    }
}
