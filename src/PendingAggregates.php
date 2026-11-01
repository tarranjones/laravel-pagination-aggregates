<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Database\Eloquent\Builder;

/**
 * Stores the aggregates declared with the withAgg*() macros on the Eloquent builder itself.
 *
 * The coordinator lives in a builder-local macro, which Eloquent copies when the builder is
 * cloned. An onClone() hook then swaps the clone's macro for a copy of the coordinator, so
 * aggregates declared on a clone never leak back into the original (or vice versa).
 */
final class PendingAggregates
{
    private const string MACRO = 'paginationAggregatesCoordinator';

    /**
     * The coordinator for this builder, created (and attached) on first use.
     *
     * @param  Builder<*>  $builder
     */
    public static function for(Builder $builder): AggregateCoordinator
    {
        $coordinator = self::find($builder);

        if ($coordinator instanceof AggregateCoordinator) {
            return $coordinator;
        }

        $coordinator = new AggregateCoordinator($builder->getQuery()->getGrammar());

        self::attach($builder, $coordinator);

        $builder->onClone(static function (Builder $clone): void {
            $source = self::find($clone);

            if ($source instanceof AggregateCoordinator) {
                self::attach($clone, clone $source);
            }
        });

        return $coordinator;
    }

    /**
     * The coordinator for this builder, or null when no aggregates were declared.
     *
     * @param  Builder<*>  $builder
     */
    public static function find(Builder $builder): ?AggregateCoordinator
    {
        if (! $builder->hasMacro(self::MACRO)) {
            return null;
        }

        return ($builder->getMacro(self::MACRO))();
    }

    /**
     * @param  Builder<*>  $builder
     */
    private static function attach(Builder $builder, AggregateCoordinator $coordinator): void
    {
        $builder->macro(self::MACRO, fn (): AggregateCoordinator => $coordinator);
    }
}
