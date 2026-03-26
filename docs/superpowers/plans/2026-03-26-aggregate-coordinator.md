# AggregateCoordinator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse three traits into one thin delegation trait by moving all aggregate logic into a new `AggregateCoordinator` class.

**Architecture:** A new `AggregateCoordinator` class absorbs `BuildsAggregateInstructions` and `HasAggregates` in full. The `AggregatesPaginator` trait shrinks to a coordinator property plus six proxy methods and a `toArray()` override. The three concrete paginators switch from `$this->builder` to `$this->coordinator` in their constructors.

**Tech Stack:** PHP 8.5, Laravel Eloquent paginators, Pest tests.

---

### Task 1: Verify baseline tests pass

**Files:**
- (none changed)

- [ ] **Step 1: Run the full test suite**

```bash
vendor/bin/pest --compact
```

Expected: all tests pass (green). If any fail, stop and fix before proceeding — the tests are the regression net for this refactor.

---

### Task 2: Create `AggregateCoordinator`

**Files:**
- Create: `src/AggregateCoordinator.php`

- [ ] **Step 1: Create the file**

```php
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
            $alias = $as ?? $this->aliasResolver()->forColumn($column, $function);

            $storedColumn = ($as !== null && is_string($column))
                ? AliasResolver::stripAlias($column)
                : $column;

            $this->instructions[] = new AggregateInstruction(
                $function, $alias, $storedColumn, null, $constraint,
            );

            $this->cachedValues = null;

            return $this;
        }

        $relations = is_array($relations) ? $relations : [$relations];

        if ($relations === []) {
            throw new InvalidArgumentException('Aggregate relation list cannot be empty.');
        }

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
```

- [ ] **Step 2: Run tests to confirm nothing broke**

```bash
vendor/bin/pest --compact
```

Expected: all tests still pass. The new class is unused at this point.

- [ ] **Step 3: Commit**

```bash
git add src/AggregateCoordinator.php
git commit -m "feat: add AggregateCoordinator class"
```

---

### Task 3: Wire up the coordinator — trait, paginators, cleanup

All five file changes in this task must land together before the tests can pass. Make all edits, then run tests once at the end.

**Files:**
- Modify: `src/AggregatesPaginator.php`
- Modify: `src/Pagination/LengthAwarePaginator.php`
- Modify: `src/Pagination/Paginator.php`
- Modify: `src/Pagination/CursorPaginator.php`
- Delete: `src/Concerns/BuildsAggregateInstructions.php`
- Delete: `src/Concerns/HasAggregates.php`

- [ ] **Step 1: Replace `src/AggregatesPaginator.php`**

Replace the entire file with:

```php
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
```

- [ ] **Step 2: Replace `src/Pagination/LengthAwarePaginator.php`**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as BaseLengthAwarePaginator;
use Illuminate\Pagination\Paginator as PagePaginator;
use TarranJones\LaravelPaginationAggregates\AggregateCoordinator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

class LengthAwarePaginator extends BaseLengthAwarePaginator
{
    use AggregatesPaginator;

    private bool $initialized = false;

    public function __construct(
        Builder $builder,
        private ?int $pendingPerPage = null,
        /** @var array<int, string> */
        private array $pendingColumns = ['*'],
        private string $pendingPageName = 'page',
        private ?int $pendingPage = null,
    ) {
        $this->coordinator = new AggregateCoordinator(clone $builder);

        $resolvedPerPage = is_int($this->pendingPerPage) ? $this->pendingPerPage : $builder->getModel()->getPerPage();
        $resolvedPage = $this->pendingPage ?? PagePaginator::resolveCurrentPage($this->pendingPageName);

        parent::__construct(
            $builder->getModel()->newCollection(),
            0,
            $resolvedPerPage,
            $resolvedPage,
            ['path' => PagePaginator::resolveCurrentPath(), 'pageName' => $this->pendingPageName],
        );
    }

    protected function initializePaginator(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $aggregates = $this->coordinator->resolve();

        $lengthAwarePaginator = (clone $this->coordinator->builder())->paginate(
            $this->pendingPerPage,
            $this->pendingColumns,
            $this->pendingPageName,
            $this->pendingPage,
            $this->extractTotalFromAggregates($aggregates),
        );

        $this->items = $lengthAwarePaginator->getCollection();
        $this->total = $lengthAwarePaginator->total();
        $this->lastPage = $lengthAwarePaginator->lastPage();
        $this->perPage = $lengthAwarePaginator->perPage();
        $this->currentPage = $lengthAwarePaginator->currentPage();
    }

    /**
     * @param  array<string, mixed>  $aggregates
     */
    private function extractTotalFromAggregates(array $aggregates): ?int
    {
        foreach ($this->coordinator->instructions() as $instruction) {
            if ($instruction->function === 'count' && $instruction->relations === null) {
                return (int) ($aggregates[$instruction->alias] ?? 0);
            }
        }

        return null;
    }
}
```

- [ ] **Step 3: Replace `src/Pagination/Paginator.php`**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator as BasePaginator;
use TarranJones\LaravelPaginationAggregates\AggregateCoordinator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

class Paginator extends BasePaginator
{
    use AggregatesPaginator;

    private bool $initialized = false;

    public function __construct(
        Builder $builder,
        private ?int $pendingPerPage = null,
        /** @var array<int, string> */
        private array $pendingColumns = ['*'],
        private string $pendingPageName = 'page',
        private ?int $pendingPage = null,
    ) {
        $this->coordinator = new AggregateCoordinator(clone $builder);

        $resolvedPerPage = is_int($this->pendingPerPage) ? $this->pendingPerPage : $builder->getModel()->getPerPage();
        $resolvedPage = $this->pendingPage ?? BasePaginator::resolveCurrentPage($this->pendingPageName);

        parent::__construct(
            $builder->getModel()->newCollection(),
            $resolvedPerPage,
            $resolvedPage,
            ['path' => BasePaginator::resolveCurrentPath(), 'pageName' => $this->pendingPageName],
        );
    }

    protected function initializePaginator(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $paginator = (clone $this->coordinator->builder())->simplePaginate(
            $this->pendingPerPage,
            $this->pendingColumns,
            $this->pendingPageName,
            $this->pendingPage,
        );

        $this->items = $paginator->getCollection();
        $this->hasMore = $paginator->hasMorePages();
    }
}
```

- [ ] **Step 4: Replace `src/Pagination/CursorPaginator.php`**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace TarranJones\LaravelPaginationAggregates\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator as BaseCursorPaginator;
use Illuminate\Pagination\Paginator as PagePaginator;
use TarranJones\LaravelPaginationAggregates\AggregateCoordinator;
use TarranJones\LaravelPaginationAggregates\AggregatesPaginator;

class CursorPaginator extends BaseCursorPaginator
{
    use AggregatesPaginator;

    private bool $initialized = false;

    public function __construct(
        Builder $builder,
        private ?int $pendingPerPage = null,
        /** @var array<int, string> */
        private array $pendingColumns = ['*'],
        private string $pendingCursorName = 'cursor',
        private Cursor|string|null $pendingCursor = null,
    ) {
        $this->coordinator = new AggregateCoordinator(clone $builder);

        $resolvedPerPage = is_int($this->pendingPerPage) ? $this->pendingPerPage : $builder->getModel()->getPerPage();
        $resolvedCursor = $this->pendingCursor instanceof Cursor || $this->pendingCursor === null
            ? $this->pendingCursor
            : Cursor::fromEncoded($this->pendingCursor);

        parent::__construct(
            $builder->getModel()->newCollection(),
            $resolvedPerPage,
            $resolvedCursor,
            [
                'path' => PagePaginator::resolveCurrentPath(),
                'cursorName' => $this->pendingCursorName,
                'parameters' => [],
            ],
        );
    }

    protected function initializePaginator(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $cursorPaginator = (clone $this->coordinator->builder())->cursorPaginate(
            $this->pendingPerPage,
            $this->pendingColumns,
            $this->pendingCursorName,
            $this->pendingCursor,
        );

        $this->items = $cursorPaginator->getCollection();
        $this->hasMore = $cursorPaginator->hasMore;
        $this->parameters = $cursorPaginator->getOptions()['parameters'];
        $this->options['parameters'] = $this->parameters;
    }
}
```

- [ ] **Step 5: Delete the old trait files**

```bash
rm src/Concerns/BuildsAggregateInstructions.php
rm src/Concerns/HasAggregates.php
rmdir src/Concerns
```

- [ ] **Step 6: Run the full test suite**

```bash
vendor/bin/pest --compact
```

Expected: all tests pass. If any fail, the error message will point to the specific mismatch — check that the coordinator property is initialised before `toArray()` is called and that `builder()` / `instructions()` return what `LengthAwarePaginator` expects.

- [ ] **Step 7: Commit**

```bash
git add src/AggregatesPaginator.php \
        src/Pagination/LengthAwarePaginator.php \
        src/Pagination/Paginator.php \
        src/Pagination/CursorPaginator.php
git rm src/Concerns/BuildsAggregateInstructions.php src/Concerns/HasAggregates.php
git commit -m "refactor: replace BuildsAggregateInstructions and HasAggregates traits with AggregateCoordinator class"
```
