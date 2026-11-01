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
- Returns Laravel's own paginators, fully populated: API resources, Blade and `through()` work unchanged.
- Aggregation happens in the database: only the current page of models is ever loaded into PHP.
- Relation aggregates compute correct global values — no average-of-averages bug.

## Requirements

- PHP ^8.4 (tested on 8.4 and 8.5; 8.6 tested as pre-release)
- Laravel 12 or 13

## Installation

```bash
composer require tarranjones/laravel-pagination-aggregates
```

## Quick start

### Relation attribute counts by type

Count a related model's records broken down by a status or type attribute. Each entry in the array becomes a separate aggregate keyed by its alias:

```php
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

$posts = Post::query()
    ->withAggCount([
        'comments as approved_comments' => fn (Builder $q) => $q->where('status', 'approved'),
        'comments as pending_comments'  => fn (Builder $q) => $q->where('status', 'pending'),
        'comments as rejected_comments' => fn (Builder $q) => $q->where('status', 'rejected'),
    ])
    ->paginateWithAggregates(20);
```

```json
{
    "data": [...],
    "aggregates": {
        "approved_comments": 42,
        "pending_comments": 15,
        "rejected_comments": 8
    }
}
```

### Table attribute counts by type

Count rows in the paginated table itself broken down by a column value. The `'as alias'` key scopes each count to its constraint — all are batched into a single query:

```php
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

$orders = Order::query()
    ->withAggCount([
        'as pending'    => fn (Builder $q) => $q->where('status', 'pending'),
        'as processing' => fn (Builder $q) => $q->where('status', 'processing'),
        'as shipped'    => fn (Builder $q) => $q->where('status', 'shipped'),
        'as delivered'  => fn (Builder $q) => $q->where('status', 'delivered'),
    ])
    ->paginateWithAggregates(25);
```

```json
{
    "data": [...],
    "aggregates": {
        "pending": 23,
        "processing": 18,
        "shipped": 31,
        "delivered": 76
    }
}
```

## Usage

> The paginator methods are prefixed `withAgg…` (`withAggCount()`, `withAggSum()`, …) because they compute one value across the **whole result set**. Eloquent's own `withCount()` / `withSum()` add a value **per row**, and you can still use those on the query before paginating.

### Pagination macros

Declare aggregates with the `withAgg*()` methods on the query, then paginate with one of three macros:

```php
Order::query()->paginateWithAggregates($perPage);         // LengthAwarePaginator
Order::query()->simplePaginateWithAggregates($perPage);   // Paginator (no total count)
Order::query()->cursorPaginateWithAggregates($perPage);   // CursorPaginator
```

The paginators are Laravel's own (`LengthAwarePaginator`, `Paginator`, `CursorPaginator` subclasses), fully populated when returned. The aggregates are available as `$paginator->aggregates` and are added to `toArray()` / `toJson()` / API resource output under `aggregates`.

### Reading aggregates in PHP

```php
$paginator = Order::query()
    ->withAggCount([
        'as pending'   => fn (Builder $q) => $q->where('status', 'pending'),
        'as delivered' => fn (Builder $q) => $q->where('status', 'delivered'),
    ])
    ->paginateWithAggregates(25);

$pending   = $paginator->aggregates['pending'];
$delivered = $paginator->aggregates['delivered'];
```

Aggregates are declared on the query, so they follow it through the rest of the chain: `where()` / scopes added after `withAgg*()` still apply, and a cloned query carries its own copy.

### Base query aggregates

Aggregate over the paginator's own base query — any `where`, scope, or date filter applied to the builder is automatically reflected in the totals.

```php
Comment::query()
    ->withAggCount()           // aggregates['count'] — total rows
    ->withAggMax('votes')      // aggregates['max_votes']
    ->withAggMin('votes')      // aggregates['min_votes']
    ->withAggSum('votes')      // aggregates['sum_votes']
    ->withAggAvg('votes')      // aggregates['avg_votes']
    ->withAggExists()
    ->paginateWithAggregates(20);         // aggregates['exists'] — true if any rows match
```

> **`withAggCount()` optimization:** When used with `paginateWithAggregates`, a base `withAggCount()` is reused as the paginator's `total`, saving the separate `COUNT(*)` query that `LengthAwarePaginator` normally fires. Two queries instead of three.

#### Custom alias

Use `'column as alias'` to control the output key:

```php
->withAggSum('total as revenue')     // aggregates['revenue']
->withAggMax('id as latest_id')      // aggregates['latest_id']
->withAggExists('as has_results')    // aggregates['has_results']
```

#### Constrained base aggregates

Use the array form `['as alias' => fn]` to apply a constraint to a base aggregate. Multiple entries are batched into a single query:

```php
Order::query()
    ->withAggCount([
        'as pending'    => fn (Builder $q) => $q->where('status', 'pending'),
        'as processing' => fn (Builder $q) => $q->where('status', 'processing'),
        'as shipped'    => fn (Builder $q) => $q->where('status', 'shipped'),
        'as delivered'  => fn (Builder $q) => $q->where('status', 'delivered'),
    ])
    ->paginateWithAggregates(25);
```

The same syntax works for all numeric aggregates — pass the column as the second argument:

```php
->withAggSum(['as high_value' => fn (Builder $q) => $q->where('total', '>', 1000)], 'total')
->withAggMax(['as todays_max' => fn (Builder $q) => $q->whereDate('created_at', today())], 'total')
```

Each unique constraint becomes its own single-row subquery; aggregates that share a constraint are batched into the same one. All subqueries are combined into one query:

```sql
SELECT * FROM (SELECT COUNT(*) AS `pending` FROM `orders` WHERE `status` = 'pending') AS `agg_orders`
CROSS JOIN (SELECT COUNT(*) AS `processing` FROM `orders` WHERE `status` = 'processing') AS `agg_orders_2`
-- etc.
```

### Relation aggregates

Aggregate over related models by passing a relation name alongside a column:

```php
Post::query()
    ->withAggCount('comments')              // aggregates['comments_count']
    ->withAggMax('comments', 'votes')       // aggregates['comments_max_votes']
    ->withAggMin('comments', 'votes')       // aggregates['comments_min_votes']
    ->withAggSum('comments', 'votes')       // aggregates['comments_sum_votes']
    ->withAggAvg('comments', 'votes')       // aggregates['comments_avg_votes']
    ->withAggExists('comments')
    ->paginateWithAggregates(15);            // aggregates['comments_exists']
```

Multiple relation names can be passed as separate arguments or as an array:

```php
->withAggCount('comments', 'tags')          // variadic — two separate aggregates
->withAggCount(['comments', 'tags'])        // equivalent array form
```

Results represent the full result set — even when viewing page 3, the aggregates cover all pages.

#### Custom alias

Pass `'relation as alias'` to rename the output key:

```php
->withAggMax('comments as top_vote', 'votes')   // aggregates['top_vote']
->withAggCount('comments as total_replies')     // aggregates['total_replies']
```

#### Closure constraint

Pass a closure as the array value to scope the relation aggregate query:

```php
use Illuminate\Database\Eloquent\Builder;

->withAggCount([
    'comments as approved_comments' => fn (Builder $q) => $q->where('status', 'approved'),
    'comments as pending_comments'  => fn (Builder $q) => $q->where('status', 'pending'),
])
```

Combine alias and constraint in a single array key for numeric aggregates:

```php
->withAggSum(
    ['comments as approved_vote_total' => fn (Builder $q) => $q->where('status', 'approved')],
    'votes',
)
```

### Mixing base and relation aggregates

Base query and relation aggregates can be combined freely on the same paginator:

```php
Post::query()
    ->withAggCount()                   // total posts (also used as paginator total)
    ->withAggMax('id as latest_id')    // max post ID in the result set
    ->withAggSum('comments', 'votes')  // total votes across all comments
    ->withAggCount('comments')
    ->paginateWithAggregates(15);        // total comment count
```

### Grouped, distinct and union queries

For `groupBy()`, `having()`, `distinct()` and `union()` queries, aggregates are computed over the rows the paginator returns, across all pages: one row per group, distinct value or union row.

```php
Order::query()
    ->selectRaw('status, SUM(quantity) AS units')
    ->groupBy('status')
    ->withAggCount()          // number of status groups
    ->withAggSum('units')     // sum of the selected `units` column across all groups
    ->withAggMax('units')
    ->paginateWithAggregates(10);    // largest group
```

- Base aggregates take a column **of the query's result**, such as a selected alias like `units`. A qualified name such as `orders.quantity` resolves to the result column `quantity`.
- Constraint closures (`['as paid' => fn ($q) => $q->where(...)]`) are applied to the query before grouping.
- Relation aggregates are computed for each result row and then rolled up, so the query must select the columns the relation uses (for example `post_id` for a `post()` relation, or `id` for `comments()`).
- The paginator `total` comes from Laravel's own count query.

## SQL reference

All aggregates are computed in **one query that returns one row**: every group of aggregates becomes a single-row subquery, and the subqueries are combined with `CROSS JOIN`. (A query with a single group is run directly.) Only the current page of models is ever loaded into PHP.

### Single base aggregate — scalar query

```php
Comment::query()->withAggSum('votes')->paginateWithAggregates(20);
```

```sql
SELECT SUM(`votes`) AS `sum_votes`, COUNT(*) AS `__paginator_total` FROM `comments`
```

The paginator `total` is computed in the same query (or taken from a base `withAggCount()`), so Laravel's separate `COUNT(*)` is skipped.

### Base aggregates with different constraints

```php
Order::query()
    ->withAggCount([
        'as pending' => fn (Builder $q) => $q->where('status', 'pending'),
        'as shipped' => fn (Builder $q) => $q->where('status', 'shipped'),
    ])
    ->paginateWithAggregates(25);
```

```sql
SELECT * FROM (SELECT COUNT(*) AS `pending` FROM `orders` WHERE `status` = 'pending') AS `agg_orders`
CROSS JOIN (SELECT COUNT(*) AS `shipped` FROM `orders` WHERE `status` = 'shipped') AS `agg_orders_2`
```

### HasOne / HasMany / MorphOne / MorphMany

Related rows are aggregated directly, restricted to the parents in the base query (and to the parent's morph type), so an index on the foreign key is used:

```php
Post::query()->where('published', true)->withAggCount('comments')->withAggMax('comments', 'votes')->paginateWithAggregates(15);
```

```sql
SELECT COUNT(*) AS `comments_count`, MAX(`votes`) AS `comments_max_votes`
FROM `comments`
WHERE `comments`.`post_id` IN (SELECT `posts`.`id` FROM `posts` WHERE `published` = 1)
```

### BelongsTo, BelongsToMany and other relation types

Other relations use Laravel's correlated `withAggregate()` subqueries — one value per base row — rolled up with `SUM` / `MIN` / `MAX`:

```sql
SELECT SUM(`agg_rows`.`tags_count`) AS `tags_count`
FROM (SELECT 1 AS _agg_row, (SELECT COUNT(*) FROM `tags` INNER JOIN `post_tag` ... WHERE `posts`.`id` = `post_tag`.`post_id`) AS `tags_count` FROM `posts`) AS `agg_rows`
```

Averages are always a global `SUM / COUNT` across the full result set, never an average of averages.

## Notes

- Aggregates reflect the full base query, not just the current page.
- Values have the same PHP types on every database: `count` → `int`, `exists` → `bool`, `sum` → `int|float`, `avg` → `float|null`. `max`/`min` are returned as the database gives them (they may be dates or strings).
- Tested on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 17. SQL Server is not tested and may not work.
- When the base query returns no rows, aggregates return `0` for count/sum, `false` for exists, and `null` for max/min/avg.

## Testing

```bash
composer test      # Pest
composer analyse   # PHPStan (Larastan)
composer lint      # Pint --test, Rector --dry-run, PHPStan
composer check     # lint + test
```

## Release

Tagging a release (`v*`) triggers the Packagist update workflow.

## Contributing

Issues and pull requests are welcome. Please include tests for new behavior.

## License

MIT
