<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as BaseLengthAwarePaginator;
use Illuminate\Pagination\Paginator as PagePaginator;
use TarranJones\LaravelPaginationAggregates\AggregateCoordinator;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

class LengthAwarePaginator extends BaseLengthAwarePaginator
{
    use AggregatesPaginator;

    /**
     * Reserved alias for the paginator total injected into unconstrained CROSS JOIN groups.
     * Uses a double-underscore prefix to prevent collisions with user-defined aliases.
     */
    private const string INJECTED_TOTAL_ALIAS = '__paginator_total';

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

        // When unconstrained base aggregates exist but no base COUNT is present,
        // inject a hidden COUNT(*) so the total is computed in the same CROSS JOIN
        // derived table — avoiding a separate COUNT(*) query.
        if ($this->coordinator->hasUnconstrainedBaseAggregates()
            && ! $this->coordinator->hasUnconstrainedBaseCount()) {
            $this->coordinator->withPaginatorTotal(self::INJECTED_TOTAL_ALIAS);
        }

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
     * Mirrors AggregatesPaginator::aggregate() but strips the injected total alias
     * from the public aggregates property so it is never surfaced to callers.
     *
     * Cannot call parent::aggregate() because aggregate() comes from the AggregatesPaginator
     * trait, not from BaseLengthAwarePaginator — PHP trait overrides have no parent:: target.
     */
    public function aggregate(): static
    {
        if ($this->aggregates !== null) {
            return $this;
        }

        $this->initializePaginator();

        $resolved = $this->coordinator->resolve();
        unset($resolved[self::INJECTED_TOTAL_ALIAS]);
        $this->aggregates = $resolved;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $aggregates
     */
    private function extractTotalFromAggregates(array $aggregates): ?int
    {
        // Injected total takes priority (set when unconstrained base aggregates exist but no COUNT).
        if (array_key_exists(self::INJECTED_TOTAL_ALIAS, $aggregates)) {
            return (int) ($aggregates[self::INJECTED_TOTAL_ALIAS] ?? 0);
        }

        // Fall back to a user-defined unconstrained base COUNT (e.g. ->withCount()).
        $instruction = $this->coordinator->findUnconstrainedBaseCountInstruction();

        return $instruction instanceof AggregateInstruction ? (int) ($aggregates[$instruction->alias] ?? 0) : null;
    }
}
