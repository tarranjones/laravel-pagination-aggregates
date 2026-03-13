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
    ->paginateWithAggregates(15)
    ->withCount()
    ->withRelationSum('comments as total_votes', 'votes');

return $paginator->toArray();
```

## Usage

### Pagination macros

Three macros are added to Eloquent Builder:

```php
Post::query()->paginateWithAggregates($perPage);
Post::query()->simplePaginateWithAggregates($perPage);
Post::query()->cursorPaginateWithAggregates($perPage);
```

### Direct aggregates (whole result set)

Mirror Laravel's aggregate methods. Results are returned under the `aggregates` key in `toArray()` and JSON responses.

```php
Post::query()
    ->withCount()                    // aggregates['total_count']
    ->withMax('votes')               // aggregates['total_max_votes']
    ->withMin('votes')               // aggregates['total_min_votes']
    ->withSum('votes')               // aggregates['total_sum_votes']
    ->withAvg('votes')               // aggregates['total_avg_votes']
    ->withExists()                   // aggregates['total_exists']
    ->paginateWithAggregates(15);
```

#### Column alias

Pass `'column as alias'` to rename the output key:

```php
Post::query()
    ->withMax('votes as top_vote')   // aggregates['top_vote']
    ->paginateWithAggregates(15);
```

#### Closure constraint

Pass a closure as the second argument to scope the aggregate query:

```php
Post::query()
    ->withMax('votes as top_recent_vote', fn ($q) => $q->where('active', true))
    ->paginateWithAggregates(15);
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
    ->withCount('id as pending_count', fn ($q) => $q->where('status', OrderStatus::Pending))
    ->withCount('id as paid_count', fn ($q) => $q->where('status', OrderStatus::Paid))
    ->withCount('id as cancelled_count', fn ($q) => $q->where('status', OrderStatus::Cancelled))
    ->withAvg('price as pending_avg_price', fn ($q) => $q->where('status', OrderStatus::Pending))
    ->withAvg('price as paid_avg_price', fn ($q) => $q->where('status', OrderStatus::Paid))
    ->withAvg('price as cancelled_avg_price', fn ($q) => $q->where('status', OrderStatus::Cancelled))
    ->paginateWithAggregates(15);
```

### Relation aggregates

Mirror Eloquent's `withCount`, `withSum`, etc. for relationships:

```php
Post::query()
    ->withRelationCount('comments')             // aggregates['comments_count']
    ->withRelationMax('comments', 'votes')      // aggregates['comments_max_votes']
    ->withRelationMin('comments', 'votes')      // aggregates['comments_min_votes']
    ->withRelationSum('comments', 'votes')      // aggregates['comments_sum_votes']
    ->withRelationAvg('comments', 'votes')      // aggregates['comments_avg_votes']
    ->withRelationExists('comments')            // aggregates['comments_exists']
    ->paginateWithAggregates(15);
```

Use `'relation as alias'` to rename the key:

```php
Post::query()
    ->withRelationCount('comments as total_comments')
    ->paginateWithAggregates(15);
```

Use an array with a closure to constrain the relation query:

```php
Post::query()
    ->withRelationCount(['comments' => fn ($q) => $q->where('approved', true)])
    ->paginateWithAggregates(15);
```

### Custom aggregates key

Change the `aggregates` key name in the JSON output:

```php
Post::query()
    ->aggregateMetaKey('meta')
    ->paginateWithAggregates(15);
// JSON output uses 'meta' instead of 'aggregates'
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
