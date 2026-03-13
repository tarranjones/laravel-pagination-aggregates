<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AggregateLengthAwarePaginator extends LengthAwarePaginator
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
        $paginator = $builder->paginate($perPage, $columns, $pageName, $page);
        parent::__construct(
            $paginator->getCollection(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => $paginator->path(), 'pageName' => $paginator->getPageName()],
        );
    }
}
