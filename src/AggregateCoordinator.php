<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use TarranJones\LaravelPaginationAggregates\Resolvers\AggregateResolver;

class AggregateCoordinator
{
    /** @var AggregateInstruction[] */
    private array $instructions = [];

    /** @var array<string, mixed>|null */
    private ?array $cachedValues = null;

    private ?AliasResolver $aliasResolver = null;

    public function __construct(private readonly Builder $builder) {}

    public function withCount(string|array|null $relations = null, string|array ...$extra): static
    {
        if ($relations === null) {
            return $this->withAggregate(null, '*', 'count');
        }

        return $this->withAggregate(
            is_array($relations) ? $relations : [$relations, ...$extra],
            '*',
            'count',
        );
    }

    public function withMax(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'max');
    }

    public function withMin(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'min');
    }

    public function withSum(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'sum');
    }

    public function withAvg(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->withAggregate($relation, $column, 'avg');
    }

    public function withExists(string|array|null $relations = null, string|array ...$extra): static
    {
        if ($relations === null) {
            return $this->withAggregate(null, '*', 'exists');
        }

        return $this->withAggregate(
            is_array($relations) ? $relations : [$relations, ...$extra],
            '*',
            'exists',
        );
    }

    /** @return array<string, mixed> */
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

    /**
     * Returns true when at least one instruction targets the base query via DB (not Enumerable).
     * Includes both unconstrained and Closure-constrained base instructions.
     * Used by LengthAwarePaginator to decide whether to bundle __paginator_total into the
     * aggregate query so that paginate() can skip its own COUNT.
     */
    public function hasBaseDbInstructions(): bool
    {
        return array_any($this->instructions, fn ($instruction): bool => $instruction->relations === null && ! ($instruction->constraint instanceof Enumerable));
    }

    /**
     * Returns true when an unconstrained base COUNT(*) instruction already exists.
     * When true, LengthAwarePaginator reuses it as the paginator total instead of injecting one.
     */
    public function hasUnconstrainedBaseCount(): bool
    {
        return $this->findUnconstrainedBaseCountInstruction() instanceof AggregateInstruction;
    }

    /**
     * Returns the first unconstrained base COUNT(*) instruction, or null if none exists.
     * Used to locate the alias when extracting the paginator total from aggregate results.
     */
    public function findUnconstrainedBaseCountInstruction(): ?AggregateInstruction
    {
        return array_find($this->instructions, fn ($instruction): bool => $instruction->function === 'count'
            && $instruction->relations === null
            && $instruction->constraint === null);
    }

    /**
     * Inject a hidden COUNT(*) instruction with the given alias so it is batched into the
     * same unconstrained CROSS JOIN derived table as any other base aggregates.
     * For internal use by LengthAwarePaginator only.
     */
    public function withPaginatorTotal(string $alias): static
    {
        $this->instructions[] = new AggregateInstruction('count', $alias, '*', null);
        $this->cachedValues = null;

        return $this;
    }

    private function withAggregate(
        string|array|null $relations,
        Expression|string $column,
        string $function,
        ?string $as = null,
    ): static {
        if ($relations === null) {
            return $this->addBaseAggregate($column, $function, $as, null);
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
        Closure|Enumerable|null $constraint,
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
     * @param  array<int|string, mixed>  $relations
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

            if ($baseAlias = AliasResolver::baseAlias($name)) {
                $this->addBaseAggregate(
                    $column,
                    $function,
                    $baseAlias,
                    $constraints instanceof Closure || $constraints instanceof Enumerable ? $constraints : null,
                );

                continue;
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
        return $this->aliasResolver ??= new AliasResolver($this->builder->getQuery()->getGrammar());
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
