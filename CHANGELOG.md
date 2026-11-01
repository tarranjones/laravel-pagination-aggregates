# Changelog

## [2.0.0] - 2026-11-01

### Added
- Laravel 13 support (`illuminate/*` `^12.0 || ^13.0`).
- PHPStan (Larastan) static analysis at level 8, `composer analyse` / `lint` / `check` scripts.
- Dependabot updates for GitHub Actions.
- Clear `InvalidArgumentException`s for invalid aggregate arguments (a numeric aggregate without a column, an `Expression` passed as the relation, or a non-string relation entry) instead of PHP type errors.
- CI matrix: PHP 8.4 and 8.5 × Laravel 12 and 13 × lowest/stable dependencies, plus PHP 8.6 (pre-release, non-blocking).
- CI runs the full suite against MySQL 8.4, MariaDB 11.4 and PostgreSQL 17 on Laravel 12 and 13.

### Changed
- **Breaking:** aggregates are declared on the query, before paginating, with methods renamed so they can't be mistaken for Eloquent's per-row `withCount()` family:

  ```php
  // 1.x
  Post::query()->paginateWithAggregates(15)->withCount('comments');

  // 2.0
  Post::query()->withAggCount('comments')->paginateWithAggregates(15);
  ```

  | 1.x (on the paginator) | 2.0 (on the query) |
  |---|---|
  | `withCount()` | `withAggCount()` |
  | `withSum()` | `withAggSum()` |
  | `withMax()` | `withAggMax()` |
  | `withMin()` | `withAggMin()` |
  | `withAvg()` | `withAggAvg()` |
  | `withExists()` | `withAggExists()` |
  | `aggregate()` | removed: `$paginator->aggregates` is populated when the paginator is returned |

  Arguments, aliases and output keys are unchanged.
- **Breaking:** the paginators are no longer lazy. `paginateWithAggregates()` and friends run their queries immediately and return Laravel's own paginators (as subclasses) with `aggregates` attached, instead of deferring work until the paginator was read.
- Depend on `illuminate/database`, `illuminate/pagination` and `illuminate/support` instead of the full `laravel/framework`.
- Aggregates are computed in one query that returns one row. Previously every row of the base query was hydrated into PHP to compute relation aggregates. HasOne / HasMany / morph aggregates now read only the related rows of matching parents (`fk IN (...)`) instead of grouping the whole related table, and base aggregates no longer re-scan the base table: on 200k parents / 1M children this went from 2–10× slower than equivalent hand-written queries to 0.8–1.3×.
- `exists` aggregates return `false` instead of `null` when the base query has no rows.
- `composer.lock` is no longer committed.
- `laravel/boost` removed from the development dependencies.
- **Breaking:** aggregate values have the same PHP types on every driver: `count` → `int`, `exists` → `bool`, `sum` → `int|float` (`0` when empty), `avg` → `float|null`. MySQL and PostgreSQL previously returned `SUM()`/`AVG()` as numeric strings. `max`/`min` are returned unchanged.

### Fixed
- `items()`, `total()`, `count()`, iteration, `through()` and `JsonResource::collection()` returned empty data until the paginator was serialized (fixed by removing laziness altogether).
- Base aggregates failed on PostgreSQL when the query had an `orderBy()` (the `ORDER BY` leaked into the aggregate subquery).
- Wrong paginator `total` for `groupBy()` / `having()` / `distinct()` / union base queries.
- Base aggregates on `groupBy()` / `having()` / `distinct()` / union queries returned a value for one group (or counted underlying rows). They are now computed over the query's result rows via a derived table.
- Relation aggregates on grouped queries failed on MySQL and PostgreSQL (`GROUP BY` error). They are now computed per result row and rolled up on every driver.
- `MorphOne` / `MorphMany` aggregates ignored the morph type and counted related rows of other models with the same id.
- `withAvg()` on `BelongsTo`, `HasManyThrough` and other non-`HasOneOrMany`/`BelongsToMany` relations threw `BadMethodCallException`.

### Removed
- `rector-fix` workflow that committed directly to `main`.

## [1.0.1] - 2026-03-17

### Added
- Allow `withCount()` and `withExists()` without a relation to target the current query
- Allow single-argument `withMax/withMin/withSum/withAvg` to target the current query column
- Lazy pagination execution until serialization (`toArray`, `toJson`, `toResponse`)

## [1.0.0] - 2026-03-13

### Added
- `paginateWithAggregates()`, `simplePaginateWithAggregates()`, `cursorPaginateWithAggregates()` macros on Eloquent Builder
- Direct aggregates: `withCount()`, `withMax()`, `withMin()`, `withSum()`, `withAvg()`, `withExists()`
- Relation aggregates: `withRelationCount()`, `withRelationMax()`, `withRelationMin()`, `withRelationSum()`, `withRelationAvg()`, `withRelationExists()`
- Column alias support via `'column as alias'` syntax
- Closure constraint support for scoped aggregates
- Custom aggregates JSON key via `aggregateMetaKey()`
- Correct global average for relation aggregates (sum/count, not average-of-averages)
