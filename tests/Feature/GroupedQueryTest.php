<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Order;
use Tests\Fixtures\Models\Post;

uses(RefreshDatabase::class);

/*
 * Grouped, HAVING, DISTINCT and UNION base queries: base aggregates are computed over the
 * rows the paginator returns (across all pages) — one row per group, per distinct value, etc.
 */

beforeEach(function (): void {
    Schema::create('orders', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('status');
        $blueprint->integer('quantity');
        $blueprint->decimal('total', 8, 2);
    });

    // Groups by status: paid (2 orders, 5 units), pending (1 order, 4 units), shipped (1 order, 1 unit)
    Order::query()->create(['status' => 'paid', 'quantity' => 2, 'total' => '10.25']);
    Order::query()->create(['status' => 'paid', 'quantity' => 3, 'total' => '20.50']);
    Order::query()->create(['status' => 'pending', 'quantity' => 4, 'total' => '5.00']);
    Order::query()->create(['status' => 'shipped', 'quantity' => 1, 'total' => '7.00']);

    Schema::create('posts', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('title');
    });

    Schema::create('comments', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('post_id');
        $blueprint->integer('votes');
    });

    $first = Post::query()->create(['title' => 'First']);
    $second = Post::query()->create(['title' => 'Second']);

    Comment::query()->create(['post_id' => $first->id, 'votes' => 1]);
    Comment::query()->create(['post_id' => $first->id, 'votes' => 2]);
    Comment::query()->create(['post_id' => $second->id, 'votes' => 3]);
});

function unitsByStatus(): Builder
{
    return Order::query()
        ->selectRaw('status, SUM(quantity) AS units')
        ->groupBy('status')
        ->orderBy('status');
}

it('counts groups, not underlying rows', function (): void {
    $paginator = unitsByStatus()->withAggCount()->paginateWithAggregates(2);

    expect($paginator->toArray()['aggregates']['count'])->toBe(3)
        ->and($paginator->total())->toBe(3);
});

it('aggregates a column selected by the grouped query', function (): void {
    $aggregates = unitsByStatus()
        ->withAggSum('units')
        ->withAggMax('units')
        ->withAggMin('units')
        ->withAggAvg('units')
        ->paginateWithAggregates(2)
        ->toArray()['aggregates'];

    expect($aggregates['sum_units'])->toBe(10)
        ->and((int) $aggregates['max_units'])->toBe(5)
        ->and((int) $aggregates['min_units'])->toBe(1)
        ->and($aggregates['avg_units'])->toEqualWithDelta(10 / 3, 0.0001);
});

it('respects HAVING', function (): void {
    $aggregates = unitsByStatus()
        ->havingRaw('SUM(quantity) > 3')
        ->withAggCount()
        ->withAggSum('units')
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    // paid (5) and pending (4)
    expect($aggregates['count'])->toBe(2)
        ->and($aggregates['sum_units'])->toBe(9);
});

it('applies constraints before grouping', function (): void {
    $aggregates = unitsByStatus()
        ->withAggCount([
            'as paid_groups' => fn (Builder $builder): Builder => $builder->where('status', 'paid'),
            'as large_order_groups' => fn (Builder $builder): Builder => $builder->where('quantity', '>=', 3),
        ])
        ->withAggExists(['as has_refunds' => fn (Builder $builder): Builder => $builder->where('status', 'refunded')])
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    expect($aggregates['paid_groups'])->toBe(1)
        ->and($aggregates['large_order_groups'])->toBe(2)
        ->and($aggregates['has_refunds'])->toBeFalse();
});

it('aggregates distinct rows', function (): void {
    $aggregates = Order::query()
        ->select('status')
        ->distinct()
        ->withAggCount()
        ->withAggExists()
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    expect($aggregates['count'])->toBe(3)
        ->and($aggregates['exists'])->toBeTrue();
});

it('resolves qualified column names against the distinct result', function (): void {
    $aggregates = Order::query()
        ->select('status', 'quantity')
        ->distinct()
        ->withAggSum('orders.quantity')
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    expect($aggregates['sum_orders_quantity'])->toBe(10);
});

it('aggregates union rows', function (): void {
    $aggregates = Order::query()
        ->select('id', 'quantity')
        ->where('status', 'paid')
        ->union(Order::query()->select('id', 'quantity')->where('status', 'shipped'))
        ->withAggCount()
        ->withAggSum('quantity')
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    expect($aggregates['count'])->toBe(3)
        ->and($aggregates['sum_quantity'])->toBe(6);
});

it('returns empty defaults when no groups match', function (): void {
    $aggregates = unitsByStatus()
        ->where('status', 'refunded')
        ->withAggCount()
        ->withAggSum('units')
        ->withAggAvg('units')
        ->withAggExists()
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    expect($aggregates)->toBe([
        'count' => 0,
        'sum_units' => 0,
        'avg_units' => null,
        'exists' => false,
    ]);
});

it('keeps page data and total correct alongside aggregates', function (): void {
    $payload = unitsByStatus()->withAggSum('units')->paginateWithAggregates(2, page: 2)->toArray();

    expect($payload['total'])->toBe(3)
        ->and($payload['data'])->toHaveCount(1)
        ->and($payload['data'][0]['status'])->toBe('shipped');
});

// ─── Relation aggregates: computed per result row, then rolled up ────────────

it('computes BelongsTo aggregates per group', function (): void {
    // Two groups (post 1, post 2); each group row belongs to one post.
    $aggregates = Comment::query()
        ->select('post_id')
        ->groupBy('post_id')
        ->withAggCount('post')
        ->withAggMax('post', 'id')
        ->paginateWithAggregates(1)
        ->toArray()['aggregates'];

    expect($aggregates['post_count'])->toBe(2)
        ->and($aggregates['post_max_id'])->toBe(2);
});

it('computes HasMany aggregates per group', function (): void {
    $aggregates = Post::query()
        ->select('id', 'title')
        ->groupBy('id', 'title')
        ->withAggCount('comments')
        ->withAggSum('comments', 'votes')
        ->withAggAvg('comments', 'votes')
        ->paginateWithAggregates(1)
        ->toArray()['aggregates'];

    expect($aggregates['comments_count'])->toBe(3)
        ->and($aggregates['comments_sum_votes'])->toBe(6)
        ->and($aggregates['comments_avg_votes'])->toBe(2.0);
});

it('computes relation aggregates for distinct queries', function (): void {
    $aggregates = Post::query()
        ->distinct()
        ->withAggCount('comments')
        ->withAggExists('comments')
        ->paginateWithAggregates(1)
        ->toArray()['aggregates'];

    expect($aggregates['comments_count'])->toBe(3)
        ->and($aggregates['comments_exists'])->toBeTrue();
});

it('mixes base and relation aggregates on a grouped query, in declaration order', function (): void {
    $aggregates = Comment::query()
        ->selectRaw('post_id, SUM(votes) AS votes_per_post')
        ->groupBy('post_id')
        ->withAggCount('post')
        ->withAggCount()
        ->withAggMax('votes_per_post')
        ->paginateWithAggregates(1)
        ->toArray()['aggregates'];

    // max/min are returned as the driver gives them (MySQL returns MAX(SUM(...)) as a decimal string).
    expect(array_keys($aggregates))->toBe(['post_count', 'count', 'max_votes_per_post'])
        ->and($aggregates['post_count'])->toBe(2)
        ->and($aggregates['count'])->toBe(2)
        ->and((int) $aggregates['max_votes_per_post'])->toBe(3);
});
