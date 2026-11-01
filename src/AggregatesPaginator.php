<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Override;
use ReflectionClass;

/**
 * Adds an `aggregates` payload to a standard Laravel paginator.
 *
 * Paginators are built by Laravel as usual and then copied onto these subclasses, so every
 * paginator method (items, total, links, through, API resources, ...) behaves exactly as it
 * does on Laravel's own paginator.
 */
trait AggregatesPaginator
{
    /** @var array<string, mixed> */
    public private(set) array $aggregates = [];

    /**
     * Copy a paginator built by Laravel onto this subclass and attach the resolved aggregates.
     *
     * Copying preserves state Laravel computes while constructing the paginator (for example
     * `hasMore`, or the reversed item order of a cursor pointing at previous items), which
     * re-running the constructor would recompute incorrectly.
     *
     * @param  array<string, mixed>  $aggregates
     */
    public static function fromPaginator(object $paginator, array $aggregates): static
    {
        $instance = new ReflectionClass(static::class)->newInstanceWithoutConstructor();

        foreach (get_object_vars($paginator) as $property => $value) {
            $instance->{$property} = $value;
        }

        $instance->aggregates = $aggregates;

        return $instance;
    }

    #[Override]
    public function toArray(): array
    {
        $payload = parent::toArray();

        if ($this->aggregates !== []) {
            $payload['aggregates'] = $this->aggregates;
        }

        return $payload;
    }
}
