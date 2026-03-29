<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use TarranJones\LaravelPaginationAggregates\Resolvers\AggregateResolver;

class AggregateCoordinator
{
    /** @var AggregateInstruction[] */
    private array $instructions = [];

    /** @var array<string, mixed>|null */
    private ?array $cachedValues = null;

    public function __construct(private readonly Builder $builder) {}

    public function withCount(
        string|array|null $relations = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        if ($relations === null) {
            return $this->withAggregate(null, '*', 'count', $as, $constraint);
        }

        $all = is_array($relations) ? $relations : [$relations];

        return $this->withAggregate($all, '*', 'count');
    }

    public function withMax(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'max', $as, $constraint);
    }

    public function withMin(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'min', $as, $constraint);
    }

    public function withSum(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'sum', $as, $constraint);
    }

    public function withAvg(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'avg', $as, $constraint);
    }

    public function withExists(
        string|array|null $relations = null,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        if ($relations === null) {
            return $this->withAggregate(null, '*', 'exists', $as, $constraint);
        }

        $all = is_array($relations) ? $relations : [$relations];

        return $this->withAggregate($all, '*', 'exists');
    }

    public function resolve(): array
    {
        if ($this->cachedValues !== null) {
            return $this->cachedValues;
        }

        if ($this->instructions === []) {
            return $this->cachedValues = [];
        }

        return $this->cachedValues = (new AggregateResolver)->resolve($this->instructions, $this->builder);
    }

    /** @return AggregateInstruction[] */
    public function instructions(): array
    {
        return $this->instructions;
    }

    public function builder(): Builder
    {
        return $this->builder;
    }

    private function withAggregate(
        string|array|null $relations,
        Expression|string $column,
        string $function,
        ?string $as = null,
        ?Closure $constraint = null,
    ): static {
        if ($relations === null) {
            return $this->addBaseAggregate($column, $function, $as, $constraint);
        }

        $relations = is_array($relations) ? $relations : [$relations];

        if ($relations === []) {
            throw new InvalidArgumentException('Aggregate relation list cannot be empty.');
        }

        return $this->addRelationAggregates($relations, $column, $function);
    }

    private function addBaseAggregate(
        Expression|string $column,
        string $function,
        ?string $as,
        ?Closure $constraint,
    ): static {
        $alias = $as ?? $this->aliasResolver()->forColumn($column, $function);

        $storedColumn = is_string($column)
            ? AliasResolver::stripAlias($column)
            : $column;

        $this->instructions[] = new AggregateInstruction(
            $function, $alias, $storedColumn, null, $constraint,
        );

        $this->cachedValues = null;

        return $this;
    }

    /**
     * @param array<int|string, mixed> $relations
     */
    private function addRelationAggregates(
        array $relations,
        Expression|string $column,
        string $function,
    ): static {
        foreach ($relations as $name => $constraints) {
            if (! is_string($name)) {
                [$name, $constraints] = [$constraints, null];
            }

            $alias = AliasResolver::explicitAlias($name)
                ?? $this->aliasResolver()->forRelation(AliasResolver::stripAlias($name), $column, $function);

            $this->instructions[] = new AggregateInstruction(
                $function, $alias, $column, [$name => $constraints],
            );
        }

        $this->cachedValues = null;

        return $this;
    }

    private function aliasResolver(): AliasResolver
    {
        return new AliasResolver($this->builder->getQuery()->getGrammar());
    }

    /**
     * @return array{0: Expression|string|array|null, 1: Expression|string}
     */
    private function normalizeAggregateParams(
        Expression|string|array|null $relation,
        Expression|string|null $column,
    ): array {
        return $column === null ? [null, $relation] : [$relation, $column];
    }
}
