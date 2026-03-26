<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator as BasePaginator;
use TarranJones\LaravelPaginationAggregates\AggregateCoordinator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

class Paginator extends BasePaginator
{
    use AggregatesPaginator;

    private bool $initialized = false;

    public function __construct(
        Builder $builder,
        private ?int $pendingPerPage = null,
        /** @var array<int, string> */
        private array $pendingColumns = ['*'],
        private string $pendingPageName = 'page',
        private ?int $pendingPage = null,
    ) {
        $this->coordinator = new AggregateCoordinator(clone $builder);

        $resolvedPerPage = is_int($this->pendingPerPage) ? $this->pendingPerPage : $builder->getModel()->getPerPage();
        $resolvedPage = $this->pendingPage ?? BasePaginator::resolveCurrentPage($this->pendingPageName);

        parent::__construct(
            $builder->getModel()->newCollection(),
            $resolvedPerPage,
            $resolvedPage,
            ['path' => BasePaginator::resolveCurrentPath(), 'pageName' => $this->pendingPageName],
        );
    }

    protected function initializePaginator(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $paginator = (clone $this->coordinator->builder())->simplePaginate(
            $this->pendingPerPage,
            $this->pendingColumns,
            $this->pendingPageName,
            $this->pendingPage,
        );

        $this->items = $paginator->getCollection();
        $this->hasMore = $paginator->hasMorePages();
    }
}
