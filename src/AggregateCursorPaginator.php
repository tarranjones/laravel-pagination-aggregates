<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

class AggregateCursorPaginator extends CursorPaginator
{
    use AggregatesPaginator;

    public function __construct(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $cursorName = 'cursor',
        Cursor|string|null $cursor = null,
    ) {
        $this->builder = clone $builder;
        $paginator = $builder->cursorPaginate($perPage, $columns, $cursorName, $cursor);
        parent::__construct(
            $paginator->getCollection(),
            $paginator->perPage(),
            $paginator->cursor,
            [
                'path' => $paginator->path(),
                'cursorName' => $paginator->getCursorName(),
                'parameters' => $paginator->parameters,
            ],
        );
    }
}
