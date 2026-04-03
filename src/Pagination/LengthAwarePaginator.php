<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as BaseLengthAwarePaginator;
use Illuminate\Pagination\Paginator as PagePaginator;
use TarranJones\LaravelPaginationAggregates\AggregateCoordinator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

class LengthAwarePaginator extends BaseLengthAwarePaginator
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
        $resolvedPage = $this->pendingPage ?? PagePaginator::resolveCurrentPage($this->pendingPageName);

        parent::__construct(
            $builder->getModel()->newCollection(),
            0,
            $resolvedPerPage,
            $resolvedPage,
            ['path' => PagePaginator::resolveCurrentPath(), 'pageName' => $this->pendingPageName],
        );
    }

    protected function initializePaginator(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $aggregates = $this->coordinator->resolve();

        $lengthAwarePaginator = (clone $this->coordinator->builder())->paginate(
            $this->pendingPerPage,
            $this->pendingColumns,
            $this->pendingPageName,
            $this->pendingPage,
            $this->extractTotalFromAggregates($aggregates),
        );

        $this->items = $lengthAwarePaginator->getCollection();
        $this->total = $lengthAwarePaginator->total();
        $this->lastPage = $lengthAwarePaginator->lastPage();
        $this->perPage = $lengthAwarePaginator->perPage();
        $this->currentPage = $lengthAwarePaginator->currentPage();
    }

    /**
     * @param  array<string, mixed>  $aggregates
     */
    private function extractTotalFromAggregates(array $aggregates): ?int
    {
        foreach ($this->coordinator->instructions() as $aggregateInstruction) {
            if ($aggregateInstruction->function === 'count'
                && $aggregateInstruction->relations === null
                && $aggregateInstruction->constraint === null) {
                return (int) ($aggregates[$aggregateInstruction->alias] ?? 0);
            }
        }

        return null;
    }
}
