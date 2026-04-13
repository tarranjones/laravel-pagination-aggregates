<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator as BaseLengthAwarePaginator;
use Illuminate\Pagination\Paginator as PagePaginator;
use Illuminate\Support\Enumerable;
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
        private ?int $pendingTotal = null,
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

        if ($this->pendingTotal !== null) {
            // Total is pre-known — skip COUNT injection entirely.
            $columns = $this->pendingColumns;

            // When all rows fit on one page, unconstrained base aggregates will be computed
            // from the loaded Collection. If columns are not ['*'], ensure any aggregate
            // columns are included in the SELECT so the data is available.
            if ($this->pendingTotal <= $this->perPage && $columns !== ['*']) {
                $extraColumns = [];

                foreach ($this->coordinator->instructions() as $aggregateInstruction) {
                    if ($this->isCollectionComputable($aggregateInstruction)
                        && $aggregateInstruction->column !== null
                        && $aggregateInstruction->column !== '*') {
                        $extraColumns[] = (string) $aggregateInstruction->column;
                    }
                }

                if ($extraColumns !== []) {
                    $columns = array_values(array_unique(array_merge($columns, $extraColumns)));
                }
            }

            $lengthAwarePaginator = (clone $this->coordinator->builder())->paginate(
                $this->pendingPerPage,
                $columns,
                $this->pendingPageName,
                $this->pendingPage,
                $this->pendingTotal,
            );
        } else {
            // When base DB instructions exist (unconstrained or Closure-constrained) but no
            // base COUNT is present, inject a hidden COUNT(*) so the total is computed in the
            // same derived table — avoiding a separate COUNT(*) query from paginate().
            if ($this->coordinator->hasBaseDbInstructions()
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
        }

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

        // When a pre-known total is provided and all rows fit on one page, compute as many
        // aggregates as possible from the loaded Collection to avoid DB round-trips.
        // If ALL instructions are collection-computable, the coordinator is skipped entirely.
        // If SOME need DB (relation, constrained, or Expression column), the coordinator runs
        // for all and collection values override the results for computable instructions.
        if ($this->pendingTotal !== null && $this->total <= $this->perPage) {
            $collectionResults = [];
            $needsDb = false;

            foreach ($this->coordinator->instructions() as $aggregateInstruction) {
                if ($this->isCollectionComputable($aggregateInstruction)) {
                    $collectionResults[$aggregateInstruction->alias] = $this->computeFromCollection($aggregateInstruction);
                } elseif ($aggregateInstruction->constraint instanceof Enumerable) {
                    $collectionResults[$aggregateInstruction->alias] = $this->computeFromEnumerable($aggregateInstruction);
                } else {
                    $needsDb = true;
                }
            }

            if ($needsDb) {
                $dbResults = $this->coordinator->resolve();
                unset($dbResults[self::INJECTED_TOTAL_ALIAS]);
                // Collection results take priority for instructions computable from items.
                $this->aggregates = array_merge($dbResults, $collectionResults);
            } else {
                $this->aggregates = $collectionResults;
            }

            return $this;
        }

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

    /**
     * Returns true when an aggregate can be computed from $this->items instead of a DB query.
     * Requires: base query (no relations), no constraint closure, and a plain string column
     * (Expression columns cannot be used as Collection method keys).
     */
    private function isCollectionComputable(AggregateInstruction $aggregateInstruction): bool
    {
        return $aggregateInstruction->relations === null
            && $aggregateInstruction->constraint === null
            && ! ($aggregateInstruction->column instanceof Expression);
    }

    /**
     * Compute an aggregate from the loaded items Collection.
     * Only call this when isCollectionComputable() returns true and all rows are on this page.
     */
    private function computeFromCollection(AggregateInstruction $aggregateInstruction): mixed
    {
        return match ($aggregateInstruction->function) {
            'count' => $this->total,
            'sum' => $this->items->sum($aggregateInstruction->column),
            'avg' => $this->items->avg($aggregateInstruction->column),
            'max' => $this->items->max($aggregateInstruction->column),
            'min' => $this->items->min($aggregateInstruction->column),
            'exists' => $this->items->isNotEmpty(),
        };
    }

    /**
     * Compute an aggregate directly from the Enumerable provided as the instruction's constraint.
     * Mirrors AggregateResolver::computeFromEnumerable() for the fits-on-one-page fast path.
     */
    private function computeFromEnumerable(AggregateInstruction $aggregateInstruction): mixed
    {
        /** @var Enumerable $items */
        $items = $aggregateInstruction->constraint;
        $col = $aggregateInstruction->column;

        return match ($aggregateInstruction->function) {
            'count' => $items->count(),
            'sum' => $items->sum($col),
            'avg' => $items->avg($col),
            'max' => $items->max($col),
            'min' => $items->min($col),
            'exists' => $items->isNotEmpty(),
        };
    }
}
