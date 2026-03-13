# Changelog

## [1.0.0] - 2026-03-13

### Added
- `paginateWithAggregates()`, `simplePaginateWithAggregates()`, `cursorPaginateWithAggregates()` macros on Eloquent Builder
- Direct aggregates: `withCount()`, `withMax()`, `withMin()`, `withSum()`, `withAvg()`, `withExists()`
- Relation aggregates: `withRelationCount()`, `withRelationMax()`, `withRelationMin()`, `withRelationSum()`, `withRelationAvg()`, `withRelationExists()`
- Column alias support via `'column as alias'` syntax
- Closure constraint support for scoped aggregates
- Custom aggregates JSON key via `aggregateMetaKey()`
- Correct global average for relation aggregates (sum/count, not average-of-averages)
