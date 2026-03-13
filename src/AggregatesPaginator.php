<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Override;
use TarranJones\LaravelPaginationAggregates\Resolvers\DirectAggregateResolver;
use TarranJones\LaravelPaginationAggregates\Resolvers\PageAggregateResolver;
use TarranJones\LaravelPaginationAggregates\Resolvers\RelationAggregateResolver;

trait AggregatesPaginator
{
    protected Builder $builder;

    /** @var AggregateInstruction[] */
    protected array $aggregateInstructions = [];

    /** @var array<string, mixed>|null */
    protected ?array $aggregateValues = null;

    #[Override]
    public function toArray(): array
    {
        return $this->appendAggregateData(parent::toArray());
    }

    public function withTotalCount(Expression|string $column = '*', ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'count');

        return $this->storeInstruction('direct', 'count', $alias, $col, null, $callback);
    }

    public function withTotalMax(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'max');

        return $this->storeInstruction('direct', 'max', $alias, $col, null, $callback);
    }

    public function withTotalMin(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'min');

        return $this->storeInstruction('direct', 'min', $alias, $col, null, $callback);
    }

    public function withTotalSum(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'sum');

        return $this->storeInstruction('direct', 'sum', $alias, $col, null, $callback);
    }

    public function withTotalAvg(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'avg');

        return $this->storeInstruction('direct', 'avg', $alias, $col, null, $callback);
    }

    public function withTotalExists(?Closure $callback = null, string $alias = 'total_exists'): static
    {
        return $this->storeInstruction('direct', 'exists', $alias, null, null, $callback);
    }

    public function withTotalCountOf(string|array ...$relations): static
    {
        if (count($relations) === 1 && is_array($relations[0])) {
            return $this->storeRelation($relations[0], '*', 'count');
        }

        return $this->storeRelation($relations, '*', 'count');
    }

    public function withTotalMaxOf(string|array $relation, Expression|string $column): static
    {
        return $this->storeRelation($relation, $column, 'max');
    }

    public function withTotalMinOf(string|array $relation, Expression|string $column): static
    {
        return $this->storeRelation($relation, $column, 'min');
    }

    public function withTotalSumOf(string|array $relation, Expression|string $column): static
    {
        return $this->storeRelation($relation, $column, 'sum');
    }

    public function withTotalAvgOf(string|array $relation, Expression|string $column): static
    {
        return $this->storeRelation($relation, $column, 'avg');
    }

    public function withTotalExistsOf(string|array $relation): static
    {
        return $this->storeRelation($relation, '*', 'exists');
    }

    public function withPageCount(Expression|string $column = '*', ?Closure $filter = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'count', 'page');

        return $this->storeInstruction('page', 'count', $alias, $col, null, $filter);
    }

    public function withPageMax(Expression|string $column, ?Closure $filter = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'max', 'page');

        return $this->storeInstruction('page', 'max', $alias, $col, null, $filter);
    }

    public function withPageMin(Expression|string $column, ?Closure $filter = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'min', 'page');

        return $this->storeInstruction('page', 'min', $alias, $col, null, $filter);
    }

    public function withPageSum(Expression|string $column, ?Closure $filter = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'sum', 'page');

        return $this->storeInstruction('page', 'sum', $alias, $col, null, $filter);
    }

    public function withPageAvg(Expression|string $column, ?Closure $filter = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->aliasResolver()->forDirect($column, 'avg', 'page');

        return $this->storeInstruction('page', 'avg', $alias, $col, null, $filter);
    }

    public function withPageExists(?Closure $filter = null, string $alias = 'page_exists'): static
    {
        return $this->storeInstruction('page', 'exists', $alias, null, null, $filter);
    }

    protected function appendAggregateData(array $payload): array
    {
        $aggregates = $this->resolveAggregateMeta();

        if ($aggregates === []) {
            return $payload;
        }

        if (isset($payload['aggregates']) && is_array($payload['aggregates'])) {
            $payload['aggregates'] = array_merge($payload['aggregates'], $aggregates);

            return $payload;
        }

        $payload['aggregates'] = $aggregates;

        return $payload;
    }

    protected function resolveAggregateMeta(): array
    {
        if ($this->aggregateValues !== null) {
            return $this->aggregateValues;
        }

        if ($this->aggregateInstructions === []) {
            return $this->aggregateValues = [];
        }

        $byType = [];

        foreach ($this->aggregateInstructions as $instruction) {
            $byType[$instruction->type][] = $instruction;
        }

        $meta = [];

        if (isset($byType['page'])) {
            $meta = array_merge($meta, (new PageAggregateResolver)->resolve($byType['page'], $this->getCollection()));
        }

        if (isset($byType['relation'])) {
            $meta = array_merge($meta, (new RelationAggregateResolver)->resolve($byType['relation'], $this->builder));
        }

        if (isset($byType['direct'])) {
            $total = method_exists($this, 'total') ? $this->total() : null;
            $meta = array_merge($meta, (new DirectAggregateResolver)->resolve(
                $byType['direct'],
                $this->builder,
                $total,
                $this->isSinglePage(),
                $this->getCollection(),
            ));
        }

        return $this->aggregateValues = $meta;
    }

    private function aliasResolver(): AliasResolver
    {
        return new AliasResolver($this->builder->getQuery()->getGrammar());
    }

    private function storeInstruction(
        string $type,
        string $function,
        string $alias,
        ?string $column,
        string|array|null $relations,
        ?Closure $callback,
    ): static {
        $this->aggregateInstructions[] = new AggregateInstruction($type, $function, $alias, $column, $relations, $callback);
        $this->aggregateValues = null;

        return $this;
    }

    private function storeRelation(string|array $relations, Expression|string $column, string $function): static
    {
        if (is_array($relations)) {
            foreach ($relations as $name => $constraints) {
                if (is_int($name)) {
                    $relation = $constraints;
                    $relationPayload = $constraints;
                } else {
                    $relation = $name;
                    $relationPayload = [$name => $constraints];
                }

                $alias = $this->aliasResolver()->forRelation($relation, $column, $function);
                $this->aggregateInstructions[] = new AggregateInstruction('relation', $function, $alias, $column, $relationPayload, null);
            }
        } else {
            $alias = $this->aliasResolver()->forRelation($relations, $column, $function);
            $this->aggregateInstructions[] = new AggregateInstruction('relation', $function, $alias, $column, $relations, null);
        }

        $this->aggregateValues = null;

        return $this;
    }

    private function isSinglePage(): bool
    {
        if (method_exists($this, 'lastPage')) {        // LengthAwarePaginator
            return $this->lastPage() === 1;
        }
        if (method_exists($this, 'currentPage')) {     // Paginator (simple)
            return $this->currentPage() === 1 && ! $this->hasMorePages();
        }

        return $this->onFirstPage() && ! $this->hasMorePages(); // CursorPaginator
    }
}
