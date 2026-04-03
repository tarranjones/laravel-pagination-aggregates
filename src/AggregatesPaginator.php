<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Illuminate\Contracts\Database\Query\Expression;
use Override;

trait AggregatesPaginator
{
    protected AggregateCoordinator $coordinator;

    /** @var array<string, mixed>|null */
    public private(set) ?array $aggregates = null;

    public function withCount(string|array|null $relations = null, string|array ...$extra): static
    {
        $this->coordinator->withCount($relations, ...$extra);

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

    public function withExists(string|array|null $relations = null, string|array ...$extra): static
    {
        $this->coordinator->withExists($relations, ...$extra);

        return $this;
    }

    public function aggregate(): static
    {
        if (method_exists($this, 'initializePaginator')) {
            $this->initializePaginator();
        }

        $this->aggregates = array_merge($this->aggregates ?? [], $this->coordinator->resolve());

        return $this;
    }

    #[Override]
    public function toArray(): array
    {
        $this->aggregate();

        return $this->appendAggregateData(parent::toArray());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function appendAggregateData(array $payload): array
    {
        if ($this->aggregates === null || $this->aggregates === []) {
            return $payload;
        }

        $payload['aggregates'] = array_merge($payload['aggregates'] ?? [], $this->aggregates);

        return $payload;
    }
}
