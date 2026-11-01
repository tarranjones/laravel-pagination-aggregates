<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use TarranJones\LaravelPaginationAggregates\Pagination\CursorPaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\LengthAwarePaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\Paginator;
use TarranJones\LaravelPaginationAggregates\Resolvers\AggregateResolver;

/**
 * Builds paginators with the aggregates declared on the query via the withAgg*() macros.
 *
 * Pagination itself is delegated to Laravel's paginate() / simplePaginate() / cursorPaginate();
 * aggregates are resolved eagerly, so the returned paginator is complete when it is returned.
 */
class PaginatorFactory
{
    /**
     * Reserved alias for the paginator total injected into unconstrained CROSS JOIN groups.
     * Uses a double-underscore prefix to prevent collisions with user-defined aliases.
     */
    private const string INJECTED_TOTAL_ALIAS = '__paginator_total';

    /**
     * @param  Builder<*>  $builder
     * @param  array<int, string>  $columns
     * @return LengthAwarePaginator<int, Model>
     */
    public static function paginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
        ?int $total = null,
    ): LengthAwarePaginator {
        $coordinator = self::coordinator($builder);
        $resolvedPerPage = $perPage ?: $builder->getModel()->getPerPage();
        $aggregates = null;

        if ($total === null) {
            [$total, $aggregates] = self::resolveTotalFromAggregates($coordinator, $builder);
        }

        // All rows fit on one page: plain base aggregates can be computed from the page itself.
        $computeFromPage = $aggregates === null && $total !== null && $total <= $resolvedPerPage;

        if ($computeFromPage) {
            $columns = self::withAggregateColumns($columns, $coordinator);
        }

        if ($aggregates === null && ! $computeFromPage) {
            $aggregates = self::resolve($coordinator, $builder);
        }

        $paginator = (clone $builder)->paginate($perPage, $columns, $pageName, $page, $total);

        $aggregates ??= self::resolveFromPage($coordinator, $builder, $paginator->getCollection(), $paginator->total());

        return LengthAwarePaginator::fromPaginator($paginator, $aggregates);
    }

    /**
     * @param  Builder<*>  $builder
     * @param  array<int, string>  $columns
     * @return Paginator<int, Model>
     */
    public static function simplePaginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): Paginator {
        $aggregates = self::resolve(self::coordinator($builder), $builder);

        return Paginator::fromPaginator((clone $builder)->simplePaginate($perPage, $columns, $pageName, $page), $aggregates);
    }

    /**
     * @param  Builder<*>  $builder
     * @param  array<int, string>  $columns
     * @return CursorPaginator<int, Model>
     */
    public static function cursorPaginate(
        Builder $builder,
        ?int $perPage = null,
        array $columns = ['*'],
        string $cursorName = 'cursor',
        Cursor|string|null $cursor = null,
    ): CursorPaginator {
        $aggregates = self::resolve(self::coordinator($builder), $builder);

        return CursorPaginator::fromPaginator((clone $builder)->cursorPaginate($perPage, $columns, $cursorName, $cursor), $aggregates);
    }

    /**
     * @param  Builder<*>  $builder
     */
    private static function coordinator(Builder $builder): AggregateCoordinator
    {
        return PendingAggregates::find($builder) ?? new AggregateCoordinator($builder->getQuery()->getGrammar());
    }

    /**
     * @param  Builder<*>  $builder
     * @return array<string, mixed>
     */
    private static function resolve(AggregateCoordinator $aggregateCoordinator, Builder $builder): array
    {
        return new AggregateResolver()->resolve($aggregateCoordinator->instructions(), clone $builder);
    }

    /**
     * When unconstrained base aggregates exist, compute the paginator total in the same CROSS JOIN
     * derived table (reusing a base withAggCount() when there is one), saving Laravel's COUNT query.
     *
     * Grouped, distinct or union queries need Laravel's own count-for-pagination logic, so the
     * total is never derived from aggregates for those.
     *
     * @param  Builder<*>  $builder
     * @return array{0: int|null, 1: array<string, mixed>|null}
     */
    private static function resolveTotalFromAggregates(AggregateCoordinator $aggregateCoordinator, Builder $builder): array
    {
        if (! $aggregateCoordinator->canDeriveTotalFromAggregates($builder)
            || ! $aggregateCoordinator->hasUnconstrainedBaseAggregates()) {
            return [null, null];
        }

        $countInstruction = $aggregateCoordinator->findUnconstrainedBaseCountInstruction();
        $withTotal = clone $aggregateCoordinator;

        if (! $countInstruction instanceof AggregateInstruction) {
            $withTotal->addPaginatorTotal(self::INJECTED_TOTAL_ALIAS);
        }

        $aggregates = self::resolve($withTotal, $builder);

        $total = (int) ($aggregates[$countInstruction->alias ?? self::INJECTED_TOTAL_ALIAS] ?? 0);

        unset($aggregates[self::INJECTED_TOTAL_ALIAS]);

        return [$total, $aggregates];
    }

    /**
     * Compute as many aggregates as possible from the loaded page (which holds every row), and
     * the rest (relation, constrained, or Expression-column aggregates) from the database.
     *
     * @param  Builder<*>  $builder
     * @param  Collection<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    private static function resolveFromPage(AggregateCoordinator $aggregateCoordinator, Builder $builder, Collection $items, int $total): array
    {
        $fromPage = [];
        $needsDatabase = false;

        foreach ($aggregateCoordinator->instructions() as $instruction) {
            if (self::isPageComputable($instruction)) {
                $fromPage[$instruction->alias] = self::computeFromPage($instruction, $items, $total);
            } else {
                $needsDatabase = true;
            }
        }

        if (! $needsDatabase) {
            return $fromPage;
        }

        // Keep declaration order; page values take priority for the instructions they cover.
        return array_merge(self::resolve($aggregateCoordinator, $builder), $fromPage);
    }

    /**
     * Ensure the columns page-computable aggregates read are selected.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private static function withAggregateColumns(array $columns, AggregateCoordinator $aggregateCoordinator): array
    {
        if ($columns === ['*']) {
            return $columns;
        }

        foreach ($aggregateCoordinator->instructions() as $instruction) {
            if (self::isPageComputable($instruction) && is_string($instruction->column) && $instruction->column !== '*') {
                $columns[] = $instruction->column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Base query, no constraint closure, and a plain string column (an Expression cannot be used
     * as a Collection key).
     */
    private static function isPageComputable(AggregateInstruction $aggregateInstruction): bool
    {
        return $aggregateInstruction->relations === null
            && ! $aggregateInstruction->constraint instanceof Closure
            && ! ($aggregateInstruction->column instanceof Expression);
    }

    /**
     * @param  Collection<array-key, mixed>  $items
     */
    private static function computeFromPage(AggregateInstruction $aggregateInstruction, Collection $items, int $total): mixed
    {
        // isPageComputable() guarantees a plain string column.
        $column = is_string($aggregateInstruction->column) ? $aggregateInstruction->column : null;

        $value = match ($aggregateInstruction->function) {
            'count' => $total,
            'sum' => $items->sum($column),
            'avg' => $items->avg($column),
            'max' => $items->max($column),
            'min' => $items->min($column),
            'exists' => $items->isNotEmpty(),
        };

        return AggregateResolver::normalizeValue($aggregateInstruction->function, $value);
    }
}
