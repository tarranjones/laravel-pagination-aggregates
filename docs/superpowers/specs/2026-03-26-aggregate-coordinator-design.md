# Design: AggregateCoordinator — traits to class + DI

**Date:** 2026-03-26
**Goal:** Reduce 3 traits to 1 thin delegation trait by extracting all real logic into a plain `AggregateCoordinator` class.

---

## Problem

Three traits currently share responsibility:

- `AggregatesPaginator` — overrides `toArray()`, orchestrates the others
- `BuildsAggregateInstructions` — owns state and builds `AggregateInstruction` objects
- `HasAggregates` — public fluent API (`withCount`, `withMax`, etc.)

All three are mixed into `LengthAwarePaginator`, `Paginator`, and `CursorPaginator`. The logic is difficult to test in isolation and the trait count makes the design hard to reason about.

---

## Approach

Extract all aggregate logic into a single `AggregateCoordinator` class. Keep one thin `AggregatesPaginator` trait whose only job is wiring the coordinator into the paginator hierarchy and proxying the public API.

---

## Section 1: `AggregateCoordinator`

**File:** `src/AggregateCoordinator.php`

Absorbs everything from `BuildsAggregateInstructions` and `HasAggregates`.

**Constructor:**
```php
public function __construct(private readonly Builder $builder) {}
```
Takes the already-cloned builder from the paginator.

**State (private):**
- `array $instructions = []`
- `?array $cachedValues = null`

**Public fluent API** (moved from `HasAggregates`):
- `withCount(string|array|null $relations, ?string $as, ?Closure $constraint): static`
- `withMax(...)`, `withMin(...)`, `withSum(...)`, `withAvg(...)`, `withExists(...)`
- All return `static`

**Internal methods** (moved from `BuildsAggregateInstructions`):
- `withAggregate(...)` — builds and appends `AggregateInstruction`, resets cache
- `aliasResolver(): AliasResolver`
- `normalizeAggregateParams(...): array`

**Resolution:**
- `resolve(): array` — delegates to `AggregateResolver`, caches result in `$cachedValues`

**Accessors** (needed by `LengthAwarePaginator`):
- `instructions(): array` — exposes instruction list so `extractTotalFromAggregates()` can inspect it
- `builder(): Builder` — exposes the builder for `initializePaginator()` to clone

---

## Section 2: Thin `AggregatesPaginator` trait

**File:** `src/AggregatesPaginator.php` (modified)

Reduced to pure delegation:

```php
trait AggregatesPaginator
{
    protected AggregateCoordinator $coordinator;

    public function withCount(...): static { $this->coordinator->withCount(...); return $this; }
    public function withMax(...): static   { $this->coordinator->withMax(...);   return $this; }
    public function withMin(...): static   { $this->coordinator->withMin(...);   return $this; }
    public function withSum(...): static   { $this->coordinator->withSum(...);   return $this; }
    public function withAvg(...): static   { $this->coordinator->withAvg(...);   return $this; }
    public function withExists(...): static{ $this->coordinator->withExists(...);return $this; }

    #[Override]
    public function toArray(): array
    {
        if (method_exists($this, 'initializePaginator')) {
            $this->initializePaginator();
        }
        return $this->appendAggregateData(parent::toArray());
    }

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

**Deleted traits:**
- `src/Concerns/BuildsAggregateInstructions.php`
- `src/Concerns/HasAggregates.php`
- `src/Concerns/` directory (empty after deletion)

---

## Section 3: Paginator class changes

All three concrete paginators change their constructors to build the coordinator instead of setting `$this->builder` directly.

**Before:**
```php
$this->builder = clone $builder;
```

**After:**
```php
$this->coordinator = new AggregateCoordinator(clone $builder);
```

**`LengthAwarePaginator::initializePaginator()` additional changes:**
- `$this->resolveAggregateMeta()` → `$this->coordinator->resolve()`
- `$this->aggregateInstructions` → `$this->coordinator->instructions()`
- `clone $this->builder` → `clone $this->coordinator->builder()`

`Paginator` and `CursorPaginator` only need the constructor change — their `initializePaginator()` methods do not touch aggregate state.

---

## Files changed

| File | Action |
|------|--------|
| `src/AggregateCoordinator.php` | Create |
| `src/AggregatesPaginator.php` | Modify (thin) |
| `src/Pagination/LengthAwarePaginator.php` | Modify |
| `src/Pagination/Paginator.php` | Modify (constructor) |
| `src/Pagination/CursorPaginator.php` | Modify (constructor) |
| `src/Concerns/BuildsAggregateInstructions.php` | Delete |
| `src/Concerns/HasAggregates.php` | Delete |

---

## Public API

Unchanged. All `withCount`, `withMax`, etc. methods remain on the paginator and return `static`.
