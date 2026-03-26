<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Override;

trait AggregatesPaginator
{
    protected AggregateCoordinator $coordinator;

    public function withCount(
        string|array|null $relations = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        $this->coordinator->withCount($relations, $as, $constraint);

        return $this;
    }

    public function withMax(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        $this->coordinator->withMax($relation, $column, $as, $constraint);

        return $this;
    }

    public function withMin(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        $this->coordinator->withMin($relation, $column, $as, $constraint);

        return $this;
    }

    public function withSum(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        $this->coordinator->withSum($relation, $column, $as, $constraint);

        return $this;
    }

    public function withAvg(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        $this->coordinator->withAvg($relation, $column, $as, $constraint);

        return $this;
    }

    public function withExists(
        string|array|null $relations = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        $this->coordinator->withExists($relations, $as, $constraint);

        return $this;
    }

    #[Override]
    public function toArray(): array
    {
        if (method_exists($this, 'initializePaginator')) {
            $this->initializePaginator();
        }

        return $this->appendAggregateData(parent::toArray());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function appendAggregateData(array $payload): array
    {
        $aggregates = $this->coordinator->resolve();

        if ($aggregates === []) {
            return $payload;
        }

        $payload['aggregates'] = array_merge($payload['aggregates'] ?? [], $aggregates);

        return $payload;
    }
}
