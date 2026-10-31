# Changelog

## [Unreleased]

### Added
- Laravel 13 support (`illuminate/*` `^12.0 || ^13.0`).
- PHPStan (Larastan) static analysis, `composer analyse` / `lint` / `check` scripts.
- CI matrix: PHP 8.4 and 8.5 × Laravel 12 and 13 × lowest/stable dependencies, plus PHP 8.6 (pre-release, non-blocking).

### Changed
- Depend on `illuminate/database`, `illuminate/pagination` and `illuminate/support` instead of the full `laravel/framework`.
- Aggregates are now collapsed to a single row inside the database. Previously every row of the base query was hydrated into PHP to compute relation aggregates.
- Pagination state is initialised on first read, not only on serialization.
- `composer.lock` is no longer committed.

### Fixed
- `items()`, `total()`, `count()`, iteration, `through()` and `JsonResource::collection()` returned empty data until the paginator was serialized.
- Wrong paginator `total` for `groupBy()` / `having()` / `distinct()` / union base queries.
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
