<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Grammar;
use InvalidArgumentException;
use TarranJones\LaravelPaginationAggregates\Resolvers\DerivedTableResolver;

class AggregateCoordinator
{
    /** @var AggregateInstruction[] */
    private array $instructions = [];

    private ?AliasResolver $aliasResolver = null;

    /**
     * Collects the aggregate instructions declared on a query with the withAgg*() builder macros.
     *
     * Holds only the grammar (for alias generation), never the builder, so it can be stored on the
     * builder itself and copied when the builder is cloned.
     */
    public function __construct(private readonly Grammar $grammar) {}

    /**
     * @param  string|array<int|string, mixed>|null  $relations
     */
    public function withAggCount(string|array|null $relations = null): static
    {
        if ($relations === null) {
            return $this->addAggregate(null, '*', 'count');
        }

        return $this->addAggregate(
            is_array($relations) ? $relations : func_get_args(),
            '*',
            'count',
        );
    }

    /**
     * @param  Expression|string|array<int|string, mixed>|null  $relation
     */
    public function withAggMax(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->addAggregate($relation, $column, 'max');
    }

    /**
     * @param  Expression|string|array<int|string, mixed>|null  $relation
     */
    public function withAggMin(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->addAggregate($relation, $column, 'min');
    }

    /**
     * @param  Expression|string|array<int|string, mixed>|null  $relation
     */
    public function withAggSum(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->addAggregate($relation, $column, 'sum');
    }

    /**
     * @param  Expression|string|array<int|string, mixed>|null  $relation
     */
    public function withAggAvg(
        Expression|string|array|null $relation = null,
        Expression|string|null $column = null,
    ): static {
        [$relation, $column] = $this->normalizeAggregateParams($relation, $column);

        return $this->addAggregate($relation, $column, 'avg');
    }

    /**
     * @param  string|array<int|string, mixed>|null  $relations
     */
    public function withAggExists(string|array|null $relations = null): static
    {
        if ($relations === null) {
            return $this->addAggregate(null, '*', 'exists');
        }

        return $this->addAggregate(
            is_array($relations) ? $relations : func_get_args(),
            '*',
            'exists',
        );
    }

    /** @return AggregateInstruction[] */
    public function instructions(): array
    {
        return $this->instructions;
    }

    /**
     * Returns true when at least one instruction targets the base query with no constraint.
     * Used by LengthAwarePaginator to decide whether to inject a paginator total.
     */
    public function hasUnconstrainedBaseAggregates(): bool
    {
        return array_any($this->instructions, fn (AggregateInstruction $instruction): bool => $instruction->relations === null && $instruction->constraint === null);
    }

    /**
     * Returns false when the base query is grouped, distinct or a union: a COUNT(*) over
     * such a query does not equal the number of paginated rows.
     */
    /**
     * @param  Builder<*>  $builder
     */
    public function canDeriveTotalFromAggregates(Builder $builder): bool
    {
        return ! DerivedTableResolver::requiresDerivedTable($builder);
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
    public function addPaginatorTotal(string $alias): static
    {
        $this->instructions[] = new AggregateInstruction('count', $alias, '*', null);

        return $this;
    }

    /**
     * @param  string|array<int|string, mixed>|null  $relations
     * @param  'count'|'max'|'min'|'sum'|'avg'|'exists'  $function
     */
    private function addAggregate(
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

    /**
     * @param  'count'|'max'|'min'|'sum'|'avg'|'exists'  $function
     */
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

        return $this;
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @param  'count'|'max'|'min'|'sum'|'avg'|'exists'  $function
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

            if (! is_string($name)) {
                throw new InvalidArgumentException('Aggregate relations must be relation names, or relation names mapped to constraint closures.');
            }

            if ($baseAlias = AliasResolver::baseAlias($name)) {
                $this->addBaseAggregate(
                    $column,
                    $function,
                    $baseAlias,
                    $constraints instanceof Closure ? $constraints : null,
                );

                continue;
            }

            $alias = AliasResolver::explicitAlias($name)
                ?? $this->aliasResolver()->forRelation(AliasResolver::stripAlias($name), $column, $function);

            $this->instructions[] = new AggregateInstruction(
                $function, $alias, $column, [$name => $constraints],
            );
        }

        return $this;
    }

    private function aliasResolver(): AliasResolver
    {
        return $this->aliasResolver ??= new AliasResolver($this->grammar);
    }

    /**
     * Support both `withAggSum('column')` (base query) and `withAggSum('relation', 'column')`.
     *
     * @param  Expression|string|array<int|string, mixed>|null  $relation
     * @return array{0: string|array<int|string, mixed>|null, 1: Expression|string}
     */
    private function normalizeAggregateParams(
        Expression|string|array|null $relation,
        Expression|string|null $column,
    ): array {
        if ($column === null) {
            if ($relation === null || is_array($relation)) {
                throw new InvalidArgumentException("A column is required, e.g. withAggSum('votes'), withAggSum('comments', 'votes') or withAggSum(['as alias' => fn (\$query) => ...], 'votes').");
            }

            return [null, $relation];
        }

        if ($relation instanceof Expression) {
            throw new InvalidArgumentException('The relation must be a relation name or an array of relations; pass an Expression as the column instead.');
        }

        return [$relation, $column];
    }
}
