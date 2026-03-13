# Laravel Pagination Aggregates

[![Tests](https://github.com/tarranjones/laravel-pagination-aggregates/actions/workflows/tests.yml/badge.svg)](https://github.com/tarranjones/laravel-pagination-aggregates/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)
[![Total Downloads](https://img.shields.io/packagist/dt/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)
[![PHP Version](https://img.shields.io/packagist/php-v/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)
[![License](https://img.shields.io/packagist/l/tarranjones/laravel-pagination-aggregates)](https://packagist.org/packages/tarranjones/laravel-pagination-aggregates)

Attach aggregate metadata (count, sum, max, min, avg, exists) to Laravel paginated responses without rewriting your paginator logic.

## Why

- Add totals and rollups alongside paginated data without losing the native paginator shape.
- Works with length-aware, simple, and cursor pagination.
- Relation aggregates compute correct global values (no average-of-averages).

## Requirements

- PHP ^8.4
- Laravel ^12.0

## Installation

```bash
composer require tarranjones/laravel-pagination-aggregates
```

## Quick start

```php
use App\Models\Post;

$paginator = Post::query()
    ->orderBy('id')
    ->paginateWithTotals(15)
    ->withTotalCount()
    ->withTotalSumOf('comments as total_votes', 'votes');

return $paginator->toArray();
```

## Usage

### Pagination macros

Three macros are added to Eloquent Builder:

```php
Post::query()->paginateWithTotals($perPage);
Post::query()->simplePaginateWithTotals($perPage);
Post::query()->cursorPaginateWithTotals($perPage);
```

### Direct aggregates (whole result set)

Mirror Laravel's aggregate methods. Results are returned under the `aggregates` key in `toArray()` and JSON responses.

```php
Post::query()
    ->paginateWithTotals(15)
    ->withTotalCount()                    // aggregates['total_count']
    ->withTotalMax('votes')               // aggregates['total_max_votes']
    ->withTotalMin('votes')               // aggregates['total_min_votes']
    ->withTotalSum('votes')               // aggregates['total_sum_votes']
    ->withTotalAvg('votes')               // aggregates['total_avg_votes']
    ->withTotalExists();                  // aggregates['total_exists']
```

#### Column alias

Pass `'column as alias'` to rename the output key:

```php
Post::query()
    ->paginateWithTotals(15)
    ->withTotalMax('votes as top_vote');  // aggregates['top_vote']
```

#### Closure constraint

Pass a closure as the second argument to scope the aggregate query:

```php
Post::query()
    ->paginateWithTotals(15)
    ->withTotalMax('votes as top_recent_vote', fn ($q) => $q->where('active', true));
```

#### Enum attribute example

Aggregate per enum value using aliases and closure constraints:

```php
namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
```

```php
namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $casts = [
        'status' => OrderStatus::class,
    ];
}
```

```php
use App\Enums\OrderStatus;
use App\Models\Order;

Order::query()
    ->paginateWithTotals(15)
    ->withTotalCount('id as pending_count', fn ($q) => $q->where('status', OrderStatus::Pending))
    ->withTotalCount('id as paid_count', fn ($q) => $q->where('status', OrderStatus::Paid))
    ->withTotalCount('id as cancelled_count', fn ($q) => $q->where('status', OrderStatus::Cancelled))
    ->withTotalAvg('price as pending_avg_price', fn ($q) => $q->where('status', OrderStatus::Pending))
    ->withTotalAvg('price as paid_avg_price', fn ($q) => $q->where('status', OrderStatus::Paid))
    ->withTotalAvg('price as cancelled_avg_price', fn ($q) => $q->where('status', OrderStatus::Cancelled));
```

### Page aggregates (current page only)

Compute aggregates over the items already loaded on the current page — zero additional DB queries. The optional closure filters collection items by the model instance.

```php
$paginator = Order::query()
    ->paginateWithTotals(15)
    ->withPageCount()
    ->withPageCount('* as completed', fn($o) => $o->status === 'complete')
    ->withPageCount('* as failed',    fn($o) => $o->status === 'failed')
    ->withPageSum('amount');
```

Available methods mirror their `withTotal*` equivalents:

```php
$paginator
    ->withPageCount()                         // aggregates['page_count']
    ->withPageMax('votes')                    // aggregates['page_max_votes']
    ->withPageMin('votes')                    // aggregates['page_min_votes']
    ->withPageSum('votes')                    // aggregates['page_sum_votes']
    ->withPageAvg('votes')                    // aggregates['page_avg_votes']
    ->withPageExists();                       // aggregates['page_exists']
```

Column aliases and filter closures work on all methods:

```php
$paginator
    ->withPageMax('votes as top')                             // aggregates['top']
    ->withPageCount('* as active', fn($m) => $m->active);    // aggregates['active']
```

### Relation aggregates

Mirror Eloquent's `withCount`, `withSum`, etc. for relationships:

```php
Post::query()
    ->paginateWithTotals(15)
    ->withTotalCountOf('comments')             // aggregates['comments_count']
    ->withTotalMaxOf('comments', 'votes')      // aggregates['comments_max_votes']
    ->withTotalMinOf('comments', 'votes')      // aggregates['comments_min_votes']
    ->withTotalSumOf('comments', 'votes')      // aggregates['comments_sum_votes']
    ->withTotalAvgOf('comments', 'votes')      // aggregates['comments_avg_votes']
    ->withTotalExistsOf('comments');           // aggregates['comments_exists']
```

Use `'relation as alias'` to rename the key:

```php
Post::query()
    ->paginateWithTotals(15)
    ->withTotalCountOf('comments as total_comments');
```

Use an array with a closure to constrain the relation query:

```php
Post::query()
    ->paginateWithTotals(15)
    ->withTotalCountOf(['comments' => fn ($q) => $q->where('approved', true)]);
```

## Response shape

```json
{
    "data": [...],
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 15,
    "to": 15,
    "total": 42,
    "aggregates": {
        "total_count": 42,
        "top_vote": 99,
        "comments_count": 130
    }
}
```

## Notes

- Aggregates are computed from the full result set, not just the current page.
- Relation averages use total sum / total count to avoid average-of-averages.
- When the base query returns no rows, relation aggregates return `0` for count/sum and `null` for max/min/avg.
- `withTotalCount()` and `withTotalExists()` never fire a query on `LengthAwarePaginator` — they reuse the total already computed during pagination.
- All direct aggregates (no callback) skip the query when the result fits on one page.

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
