# Laravel Pagination Aggregates

[![Tests](https://github.com/tarranjones/laravel-pagination-aggregates/actions/workflows/tests.yml/badge.svg)](https://github.com/tarranjones/laravel-pagination-aggregates/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)
[![Total Downloads](https://img.shields.io/packagist/dt/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)
[![PHP Version](https://img.shields.io/packagist/php-v/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)
[![License](https://img.shields.io/packagist/l/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)

Attach aggregate data (count, sum, max, min, avg, exists) to Laravel paginated responses — computed across the full result set, not just the current page.

## Why

- Add rollups alongside paginated data while keeping the native paginator shape.
- Works with length-aware, simple, and cursor pagination.
- Queries are deferred until the paginator is serialized — no wasted queries.
- Relation aggregates compute correct global values — no average-of-averages bug.

## Requirements

- PHP ^8.4
- Laravel ^12.0

## Installation

```bash
composer require tarranjones/laravel-pagination-aggregates
```

## Quick start

An order management dashboard — today's orders, paginated, with status breakdowns across the full dataset:

```php
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

$orders = Order::query()
    ->whereDate('created_at', today())
    ->lazyPaginate(25)
    ->withCount()                                             // total orders today
    ->withSum('total as revenue')                            // total revenue today
    ->withCount(['as pending_count'
        => fn (Builder $q) => $q->where('status', 'pending')])
    ->withSum(['as pending_value'
        => fn (Builder $q) => $q->where('status', 'pending')], 'price')
    ->withCount(['as processing_count'
        => fn (Builder $q) => $q->where('status', 'processing')])
    ->withCount(['as complete_count'
        => fn (Builder $q) => $q->where('status', 'complete')])
    ->withSum(['as complete_value'
        => fn (Builder $q) => $q->where('status', 'complete')], 'price')
    ->withCount(['as cancelled_count'
        => fn (Builder $q) => $q->where('status', 'cancelled')]);
```

```json
{
    "data": [...],
    "current_page": 1,
    "last_page": 4,
    "per_page": 25,
    "total": 98,
    "aggregates": {
        "count": 98,
        "revenue": 14350.00,
        "pending_count": 23,
        "pending_value": 3420.00,
        "processing_count": 18,
        "complete_count": 34,
        "complete_value": 8750.00,
        "cancelled_count": 23
    }
}
```

## Usage

### Pagination macros

This package adds three lazy paginator macros to Eloquent Builder:

```php
Order::query()->lazyPaginate($perPage);         // LengthAwarePaginator
Order::query()->lazySimplePaginate($perPage);   // Paginator (no total count)
Order::query()->lazyCursorPaginate($perPage);   // CursorPaginator
```

Pagination queries are deferred until serialization (`toArray()`, `toJson()`, or `toResponse()`). No database queries run until you need the data.

### Resolving aggregates directly

Call `aggregate()` to resolve and return the aggregate values without serializing the full paginator:

```php
$paginator = Order::query()->whereDate('created_at', today())->lazyPaginate(25)
    ->withCount()
    ->withSum('total as revenue');

$aggregates = $paginator->aggregate();
// ['count' => 98, 'revenue' => 14350.00]

$totalOrders = $aggregates['count'];
$totalRevenue = $aggregates['revenue'];
```

The result is cached — if you later call `toArray()` or serialize the paginator, the aggregate queries do not run again.

### Base query aggregates

Aggregate over the paginator's own base query — any `where`, scope, or date filter applied to the builder is automatically reflected in the totals.

```php
Comment::query()
    ->lazyPaginate(20)
    ->withCount()           // aggregates['count'] — total rows
    ->withMax('votes')      // aggregates['max_votes']
    ->withMin('votes')      // aggregates['min_votes']
    ->withSum('votes')      // aggregates['sum_votes']
    ->withAvg('votes')      // aggregates['avg_votes']
    ->withExists();         // aggregates['exists'] — true if any rows match
```

> **`withCount()` optimization:** When used with `lazyPaginate`, a base `withCount()` is reused as the paginator's `total`, saving the separate `COUNT(*)` query that `LengthAwarePaginator` normally fires. Two queries instead of three.

#### Custom alias

Use `'column as alias'` to control the output key:

```php
->withSum('total as revenue')     // aggregates['revenue']
->withMax('id as latest_id')      // aggregates['latest_id']
->withExists('as has_results')    // aggregates['has_results']
```

#### Constrained base aggregates

Use the array form `['as alias' => fn]` to apply a constraint to a base aggregate. Multiple entries are batched into a single query:

```php
Comment::query()
    ->lazyPaginate(20)
    ->withCount(['as approved_count' => fn (Builder $q) => $q->where('approved', true)])
    ->withCount(['as pending_count'  => fn (Builder $q) => $q->where('approved', false)]);
```

The same syntax works for all numeric aggregates — pass the column as the second argument:

```php
->withSum(['as high_votes' => fn (Builder $q) => $q->where('votes', '>', 10)], 'votes')
->withMax(['as recent_max' => fn (Builder $q) => $q->whereDate('created_at', today())], 'votes')
```

**Queries fired for the example above (2 base aggregates with different constraints):**

```sql
-- Constraint group 1: approved comments
SELECT (CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END) AS `approved_count`
FROM `comments`
WHERE `approved` = 1

-- Constraint group 2: pending comments (batched separately — different constraint)
SELECT COUNT(*) AS `pending_count`
FROM `comments`
WHERE `approved` = 0
```

### Relation aggregates

Aggregate over related models by passing a relation name alongside a column:

```php
Post::query()
    ->lazyPaginate(15)
    ->withCount('comments')              // aggregates['comments_count']
    ->withMax('comments', 'votes')       // aggregates['comments_max_votes']
    ->withMin('comments', 'votes')       // aggregates['comments_min_votes']
    ->withSum('comments', 'votes')       // aggregates['comments_sum_votes']
    ->withAvg('comments', 'votes')       // aggregates['comments_avg_votes']
    ->withExists('comments');            // aggregates['comments_exists']
```

Multiple relation names can be passed as separate arguments or as an array:

```php
->withCount('comments', 'tags')          // variadic — two separate aggregates
->withCount(['comments', 'tags'])        // equivalent array form
```

Results represent the full result set — even when viewing page 3, the aggregates cover all pages.

#### Custom alias

Pass `'relation as alias'` to rename the output key:

```php
->withMax('comments as top_vote', 'votes')   // aggregates['top_vote']
->withCount('comments as total_replies')     // aggregates['total_replies']
```

#### Closure constraint

Pass a closure as the array value to scope the relation aggregate query:

```php
use Illuminate\Database\Eloquent\Builder;

->withCount(['comments' => fn (Builder $q) => $q->where('approved', true)])
```

Combine alias and constraint in a single array key:

```php
->withMax(
    ['comments as top_approved_vote' => fn (Builder $q) => $q->where('approved', true)],
    'votes',
)
```

### Mixing base and relation aggregates

Base query and relation aggregates can be combined freely on the same paginator:

```php
Post::query()
    ->lazyPaginate(15)
    ->withCount()                   // total posts (also used as paginator total)
    ->withMax('id as latest_id')    // max post ID in the result set
    ->withSum('comments', 'votes')  // total votes across all comments
    ->withCount('comments');        // total comment count
```

## SQL reference

### Single base aggregate — scalar query (no JOIN)

A single base aggregate with no constraint is resolved with a direct scalar query:

```php
Comment::query()->lazyPaginate(20)->withSum('votes');
```

```sql
SELECT SUM(`votes`) FROM `comments`
```

### Multiple base aggregates — CROSS JOIN derived table

When multiple base aggregates share the same constraint (or have none), they are batched into one `CROSS JOIN`:

```php
Comment::query()->lazyPaginate(20)->withMax('votes')->withMin('votes')->withSum('votes');
```

```sql
SELECT `comments`.`id`,
       `agg_comments`.`max_votes`,
       `agg_comments`.`min_votes`,
       `agg_comments`.`sum_votes`
FROM `comments`
CROSS JOIN (
  SELECT MAX(`votes`) AS `max_votes`,
         MIN(`votes`) AS `min_votes`,
         SUM(`votes`) AS `sum_votes`
  FROM `comments`
) AS `agg_comments`
```

### HasOneOrMany relation — LEFT JOIN derived table

Multiple aggregates for the same relation and constraint set are batched into a single derived table:

```php
Post::query()->lazyPaginate(15)->withMax('comments', 'votes')->withMin('comments', 'votes');
```

```sql
SELECT `posts`.`id`,
       `agg_comments`.`comments_max_votes`,
       `agg_comments`.`comments_min_votes`
FROM `posts`
LEFT JOIN (
  SELECT `post_id`,
         MAX(`votes`) AS `comments_max_votes`,
         MIN(`votes`) AS `comments_min_votes`
  FROM `comments`
  GROUP BY `post_id`
) AS `agg_comments` ON `agg_comments`.`post_id` = `posts`.`id`
```

### BelongsToMany and other relation types

Relations that are not `HasOne` / `HasMany` fall back to correlated subqueries via Laravel's `withAggregate`. Averages are always computed as `SUM / COUNT` across the full result set to avoid the average-of-averages problem.

## Notes

- Aggregates reflect the full base query, not just the current page.
- When the base query returns no rows, relation aggregates return `0` for count/sum and `null` for max/min/avg/exists.
- Pagination queries are deferred until the paginator is serialized (`toArray()`, `toJson()`, or `toResponse()`).

## Testing

```bash
composer test
```

```bash
vendor/bin/pint --test
```

```bash
vendor/bin/rector --dry-run
```

## Release

Tagging a release (`v*`) triggers the Packagist update workflow.

## Contributing

Issues and pull requests are welcome. Please include tests for new behavior.

## License

MIT
