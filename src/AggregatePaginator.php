<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;

class AggregatePaginator extends Paginator
{
    use AggregatesPaginator;

    public function __construct(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ) {
        $this->builder = clone $builder;
        $paginator = $builder->simplePaginate($perPage, $columns, $pageName, $page);
        parent::__construct(
            $paginator->getCollection(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => $paginator->path(), 'pageName' => $paginator->getPageName()],
        );
    }
}
