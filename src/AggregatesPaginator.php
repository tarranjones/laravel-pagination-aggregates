<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Override;

trait AggregatesPaginator
{
    protected Builder $builder;

    /**
     * @var array<int, array{type: string, relations: mixed, column: Expression|string|null, function: string|null, alias: string, callback: Closure|null}>
     */
    protected array $aggregateInstructions = [];

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $aggregateValues = null;

    #[Override]
    public function toArray(): array
    {
        return $this->appendAggregateData(parent::toArray());
    }

    public function withTotalCount(Expression|string $column = '*', ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->resolveDirectAlias($column, 'count');

        return $this->storeDirectAggregate('count', $col, $alias, $callback);
    }

    public function withTotalMax(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->resolveDirectAlias($column, 'max');

        return $this->storeDirectAggregate('max', $col, $alias, $callback);
    }

    public function withTotalMin(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->resolveDirectAlias($column, 'min');

        return $this->storeDirectAggregate('min', $col, $alias, $callback);
    }

    public function withTotalSum(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->resolveDirectAlias($column, 'sum');

        return $this->storeDirectAggregate('sum', $col, $alias, $callback);
    }

    public function withTotalAvg(Expression|string $column, ?Closure $callback = null): static
    {
        ['column' => $col, 'alias' => $alias] = $this->resolveDirectAlias($column, 'avg');

        return $this->storeDirectAggregate('avg', $col, $alias, $callback);
    }

    public function withTotalExists(?Closure $callback = null, string $alias = 'total_exists'): static
    {
        return $this->storeDirectAggregate('exists', null, $alias, $callback);
    }

    public function withTotalCountOf(string|array ...$relations): static
    {
        if (count($relations) === 1 && is_array($relations[0])) {
            return $this->withAggregate($relations[0], '*', 'count');
        }

        return $this->withAggregate($relations, '*', 'count');
    }

    public function withTotalMaxOf(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'max');
    }

    public function withTotalMinOf(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'min');
    }

    public function withTotalSumOf(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'sum');
    }

    public function withTotalAvgOf(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'avg');
    }

    public function withTotalExistsOf(string|array $relation): static
    {
        return $this->withAggregate($relation, '*', 'exists');
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
            $this->aggregateValues = [];

            return $this->aggregateValues;
        }

        $meta = [];

        $relationInstructions = array_values(array_filter($this->aggregateInstructions, fn (array $i): bool => $i['type'] === 'relation'));
        $directInstructions = array_values(array_filter($this->aggregateInstructions, fn (array $i): bool => $i['type'] === 'direct'));

        if ($relationInstructions !== []) {
            $query = clone $this->builder;

            $model = $query->getModel();

            $query->select($model->getQualifiedKeyName());

            // For avg, we compute sum+count per model to derive the true global average,
            // avoiding the incorrect average-of-averages result.
            $avgInternalAliases = [];

            foreach ($relationInstructions as $instruction) {
                if ($instruction['function'] === 'avg') {
                    $baseRelation = is_string($instruction['relations'])
                        ? trim((string) preg_split('/\s+as\s+/i', $instruction['relations'], 2)[0])
                        : $instruction['relations'];

                    $sumAttr = $instruction['alias'].'__sum';
                    $countAttr = $instruction['alias'].'__cnt';

                    $sumRelation = is_string($baseRelation) ? $baseRelation.' as '.$sumAttr : $baseRelation;
                    $countRelation = is_string($baseRelation) ? $baseRelation.' as '.$countAttr : $baseRelation;

                    $query->withAggregate($sumRelation, $instruction['column'], 'sum');
                    $query->withAggregate($countRelation, $instruction['column'], 'count');

                    $avgInternalAliases[$instruction['alias']] = [$sumAttr, $countAttr];
                } else {
                    $query->withAggregate($instruction['relations'], $instruction['column'], $instruction['function']);
                }
            }

            $results = $query->get();

            foreach ($relationInstructions as $instruction) {
                $alias = $instruction['alias'];

                if ($results->isEmpty()) {
                    $meta[$alias] = match ($instruction['function']) {
                        'count', 'sum' => 0,
                        default => null,
                    };
                    continue;
                }

                if ($instruction['function'] === 'avg') {
                    [$sumAttr, $countAttr] = $avgInternalAliases[$alias];
                    $totalSum = $results->sum(fn ($m) => (float) ($m->getAttribute($sumAttr) ?? 0));
                    $totalCount = $results->sum(fn ($m) => (int) ($m->getAttribute($countAttr) ?? 0));
                    $meta[$alias] = $totalCount > 0 ? $totalSum / $totalCount : null;
                } else {
                    $values = $results->map(fn ($m) => $m->getAttribute($alias));
                    $meta[$alias] = match ($instruction['function']) {
                        'count', 'sum' => $values->sum(),
                        'max' => $values->max(),
                        'min' => $values->min(),
                        'exists' => (bool) $values->filter()->count(),
                        default => null,
                    };
                }
            }
        }

        foreach ($directInstructions as $instruction) {
            $query = clone $this->builder;

            if ($instruction['callback'] !== null) {
                ($instruction['callback'])($query);
            }

            $alias = $instruction['alias'];
            $column = $instruction['column'];

            $meta[$alias] = match ($instruction['function']) {
                'count' => $query->count(),
                'max' => $query->max($column),
                'min' => $query->min($column),
                'sum' => $query->sum($column),
                'avg' => $query->avg($column),
                'exists' => $query->exists(),
                default => null,
            };
        }

        $this->aggregateValues = $meta;

        return $this->aggregateValues;
    }

    protected function withAggregate(string|array $relations, Expression|string $column, ?string $function): static
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

                $this->aggregateInstructions[] = [
                    'type' => 'relation',
                    'relations' => $relationPayload,
                    'column' => $column,
                    'function' => $function,
                    'alias' => $this->resolveAggregateAlias($relation, $column, $function),
                    'callback' => null,
                ];
            }
        } else {
            $this->aggregateInstructions[] = [
                'type' => 'relation',
                'relations' => $relations,
                'column' => $column,
                'function' => $function,
                'alias' => $this->resolveAggregateAlias($relations, $column, $function),
                'callback' => null,
            ];
        }

        $this->aggregateValues = null;

        return $this;
    }

    protected function storeDirectAggregate(string $function, ?string $column, string $alias, ?Closure $callback = null): static
    {
        $this->aggregateInstructions[] = [
            'type' => 'direct',
            'relations' => null,
            'column' => $column,
            'function' => $function,
            'alias' => $alias,
            'callback' => $callback,
        ];

        $this->aggregateValues = null;

        return $this;
    }

    /**
     * @return array{column: string, alias: string}
     */
    protected function resolveDirectAlias(Expression|string $column, string $function): array
    {
        if ($column instanceof Expression) {
            $col = (string) $column->getValue($this->builder->getQuery()->getGrammar());
            $snaked = strtolower((string) preg_replace('/[^[:alnum:]_]/u', '_', $col));

            return ['column' => $col, 'alias' => 'total_'.$function.'_'.$snaked];
        }

        $segments = preg_split('/\s+as\s+/i', $column, 2);

        if (count($segments) === 2) {
            return ['column' => trim($segments[0]), 'alias' => trim($segments[1])];
        }

        $col = trim($column);
        $snaked = strtolower((string) preg_replace('/[^[:alnum:]_]/u', '_', $col));

        if ($function === 'count' && $col === '*') {
            return ['column' => $col, 'alias' => 'total_count'];
        }

        return ['column' => $col, 'alias' => 'total_'.$function.'_'.$snaked];
    }

    protected function resolveAggregateAlias(string $relation, Expression|string $column, ?string $function): string
    {
        $segments = explode(' ', $relation);

        if (count($segments) === 3 && strtolower($segments[1]) === 'as') {
            return $segments[2];
        }

        $columnValue = $column;

        if ($column instanceof Expression) {
            $columnValue = $column->getValue($this->builder->getQuery()->getGrammar());
        }

        $columnValue = strtolower((string) $columnValue);

        $raw = sprintf('%s %s %s', $relation, $function, $columnValue);
        $sanitized = trim((string) preg_replace(['/[^[:alnum:][:space:]_]+/u', '/\s+/'], ['_', ' '], $raw), '_');

        return str($sanitized)->snake()->value();
    }
}
