<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Error;
use Illuminate\Contracts\Database\Query\Expression;
use Override;

trait AggregatesPaginator
{
    protected AggregateCoordinator $coordinator;

    /** @var array<string, mixed>|null */
    public private(set) ?array $aggregates = null;

    /**
     * Paginator properties that are unset until the pagination query runs.
     *
     * @var list<string>
     */
    private array $deferredProperties = [];

    private bool $paginatorInitialized = false;

    /**
     * Run the deferred pagination query and populate the paginator state.
     */
    abstract protected function initializePaginator(): void;

    /**
     * Lazily run the pagination query the first time a deferred property
     * (items, total, hasMore, ...) is read by any paginator method.
     */
    public function __get(string $name): mixed
    {
        if (! in_array($name, $this->deferredProperties, true)) {
            throw new Error(sprintf('Cannot access non-public property %s::$%s', static::class, $name));
        }

        $this->ensurePaginatorInitialized();

        return $this->{$name};
    }

    /**
     * Run the deferred pagination query exactly once.
     */
    protected function ensurePaginatorInitialized(): void
    {
        if ($this->paginatorInitialized) {
            return;
        }

        $this->initializePaginator();

        $this->paginatorInitialized = true;
        $this->deferredProperties = [];
    }

    public function withCount(string|array|null $relations = null): static
    {
        $this->coordinator->withCount(...func_get_args());

        return $this;
    }

    public function withMax(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        $this->coordinator->withMax($relation, $column);

        return $this;
    }

    public function withMin(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        $this->coordinator->withMin($relation, $column);

        return $this;
    }

    public function withSum(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        $this->coordinator->withSum($relation, $column);

        return $this;
    }

    public function withAvg(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        $this->coordinator->withAvg($relation, $column);

        return $this;
    }

    public function withExists(string|array|null $relations = null): static
    {
        $this->coordinator->withExists(...func_get_args());

        return $this;
    }

    public function aggregate(): static
    {
        if ($this->aggregates !== null) {
            return $this;
        }

        $this->ensurePaginatorInitialized();

        $this->aggregates = $this->coordinator->resolve();

        return $this;
    }

    #[Override]
    public function toArray(): array
    {
        $this->aggregate();

        return $this->appendAggregateData(parent::toArray());
    }

    /**
     * Unset paginator state so the first read triggers initializePaginator() via __get().
     */
    protected function deferPaginatorState(string ...$properties): void
    {
        foreach ($properties as $property) {
            unset($this->{$property});
        }

        $this->deferredProperties = array_values($properties);
    }

    /**
     * Whether a deferred property has been assigned (e.g. via setCollection()) before initialization.
     */
    protected function isPaginatorStateSet(string $property): bool
    {
        return ! in_array($property, $this->deferredProperties, true) || isset($this->{$property});
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function appendAggregateData(array $payload): array
    {
        if (empty($this->aggregates)) {
            return $payload;
        }

        $payload['aggregates'] = array_merge($payload['aggregates'] ?? [], $this->aggregates);

        return $payload;
    }
}
