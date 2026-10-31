<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator as BaseCursorPaginator;
use Illuminate\Pagination\Paginator as PagePaginator;
use TarranJones\LaravelPaginationAggregates\AggregateCoordinator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

class CursorPaginator extends BaseCursorPaginator
{
    use AggregatesPaginator;

    public function __construct(
        Builder $builder,
        private ?int $pendingPerPage = null,
        /** @var array<int, string> */
        private array $pendingColumns = ['*'],
        private string $pendingCursorName = 'cursor',
        private Cursor|string|null $pendingCursor = null,
    ) {
        $this->coordinator = new AggregateCoordinator(clone $builder);

        $resolvedPerPage = is_int($this->pendingPerPage) ? $this->pendingPerPage : $builder->getModel()->getPerPage();
        $resolvedCursor = $this->pendingCursor instanceof Cursor || $this->pendingCursor === null
            ? $this->pendingCursor
            : Cursor::fromEncoded($this->pendingCursor);

        parent::__construct(
            $builder->getModel()->newCollection(),
            $resolvedPerPage,
            $resolvedCursor,
            [
                'path' => PagePaginator::resolveCurrentPath(),
                'cursorName' => $this->pendingCursorName,
                'parameters' => [],
            ],
        );

        $this->deferPaginatorState('items', 'hasMore', 'parameters');
    }

    protected function initializePaginator(): void
    {
        $cursorPaginator = (clone $this->coordinator->builder())->cursorPaginate(
            $this->pendingPerPage,
            $this->pendingColumns,
            $this->pendingCursorName,
            $this->pendingCursor,
        );

        if (! $this->isPaginatorStateSet('items')) {
            $this->items = $cursorPaginator->getCollection();
        }

        $this->hasMore = $cursorPaginator->hasMore;
        $this->parameters = $cursorPaginator->getOptions()['parameters'];
        $this->options['parameters'] = $this->parameters;
    }
}
