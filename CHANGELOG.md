# Changelog

## [2.0.0] - 2026-11-01

### Added
- Laravel 13 support (`illuminate/*` `^12.0 || ^13.0`).
- PHPStan (Larastan) static analysis, `composer analyse` / `lint` / `check` scripts.
- CI matrix: PHP 8.4 and 8.5 × Laravel 12 and 13 × lowest/stable dependencies, plus PHP 8.6 (pre-release, non-blocking).
- CI runs the full suite against MySQL 8.4, MariaDB 11.4 and PostgreSQL 17 on Laravel 12 and 13.

### Changed
- **Breaking:** paginator aggregate methods are renamed so they can't be mistaken for Eloquent's per-row `withCount()` family:

  | Before | After |
  |---|---|
  | `withCount()` | `withAggCount()` |
  | `withSum()` | `withAggSum()` |
  | `withMax()` | `withAggMax()` |
  | `withMin()` | `withAggMin()` |
  | `withAvg()` | `withAggAvg()` |
  | `withExists()` | `withAggExists()` |
  | `aggregate()` | `resolveAggregates()` |

  Arguments, aliases and output keys are unchanged.
- Depend on `illuminate/database`, `illuminate/pagination` and `illuminate/support` instead of the full `laravel/framework`.
- Aggregates are now collapsed to a single row inside the database. Previously every row of the base query was hydrated into PHP to compute relation aggregates.
- Pagination state is initialised on first read, not only on serialization.
- `composer.lock` is no longer committed.
- **Breaking:** aggregate values have the same PHP types on every driver: `count` → `int`, `exists` → `bool`, `sum` → `int|float` (`0` when empty), `avg` → `float|null`. MySQL and PostgreSQL previously returned `SUM()`/`AVG()` as numeric strings. `max`/`min` are returned unchanged.

### Fixed
- `items()`, `total()`, `count()`, iteration, `through()` and `JsonResource::collection()` returned empty data until the paginator was serialized.
- Base aggregates failed on PostgreSQL when the query had an `orderBy()` (the `ORDER BY` leaked into the aggregate subquery).
- Wrong paginator `total` for `groupBy()` / `having()` / `distinct()` / union base queries.
- Base aggregates on `groupBy()` / `having()` / `distinct()` / union queries returned a value for one group (or counted underlying rows). They are now computed over the query's result rows via a derived table.
- Relation aggregates on grouped queries failed on MySQL and PostgreSQL (`GROUP BY` error). They are now computed per result row and rolled up on every driver.
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
