<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Note: Post and Comment model classes are defined in AggregatesPaginatorTest.php.
// Pest loads test files alphabetically, so AggregatesPaginatorTest loads before EnumerableConstraintTest,
// making those classes available here without redefinition.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('posts', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('title');
    });

    Schema::create('comments', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('post_id');
        $blueprint->string('content');
        $blueprint->integer('votes');
    });

    $postOne = Post::query()->create(['title' => 'First']);
    $postTwo = Post::query()->create(['title' => 'Second']);

    // votes: 3, 5, 2 → sum=10, max=5, min=2, count=3
    Comment::query()->create(['post_id' => $postOne->id, 'content' => 'alpha', 'votes' => 3]);
    Comment::query()->create(['post_id' => $postOne->id, 'content' => 'beta', 'votes' => 5]);
    Comment::query()->create(['post_id' => $postTwo->id, 'content' => 'gamma', 'votes' => 2]);
});

// ─── Query-count tests ────────────────────────────────────────────────────────

it('Enumerable constraint fires no aggregate DB query', function (): void {
    $items = Comment::query()->get();

    DB::enableQueryLog();

    Comment::query()
        ->lazyPaginate(15)
        ->withSum(['as total_sum' => $items->where('votes', '>', 0)], 'votes')
        ->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // No aggregate DB query fires — the Enumerable instruction is computed from the collection.
    // However, paginate still needs to determine the total (no pre-known total provided), so
    // it fires its own COUNT + SELECT = 2 queries. To reduce to 1 query, pass a pre-known total.
    expect($queries)->toHaveCount(2);
});

it('Enumerable constraint batched with unconstrained aggregate: unconstrained uses DB, Enumerable does not add a query', function (): void {
    $items = Comment::query()->get();

    DB::enableQueryLog();

    Comment::query()
        ->lazyPaginate(15)
        ->withSum(['as high_sum' => $items->where('votes', '>', 3)], 'votes')
        ->withSum('votes')  // unconstrained — goes to DB via CROSS JOIN with injected COUNT
        ->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // CROSS JOIN aggregate (unconstrained withSum + __paginator_total) + paginate SELECT = 2 queries.
    // The Enumerable-constrained withSum adds no DB query.
    expect($queries)->toHaveCount(2);
});

// ─── Correctness tests ────────────────────────────────────────────────────────

it('withSum with Enumerable constraint returns correct filtered sum', function (): void {
    $items = Comment::query()->get();
    $highVotes = $items->where('votes', '>', 3); // only vote=5

    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withSum(['as high_sum' => $highVotes], 'votes')
        ->toArray()['aggregates'];

    expect($aggregates['high_sum'])->toBe(5);
});

it('withCount with Enumerable constraint returns count of collection items', function (): void {
    $items = Comment::query()->get();
    $filtered = $items->where('votes', '>=', 3); // votes 3 and 5

    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withCount(['as filtered_count' => $filtered])
        ->toArray()['aggregates'];

    expect($aggregates['filtered_count'])->toBe(2);
});

it('withMax and withMin with Enumerable constraint return correct values', function (): void {
    $items = Comment::query()->get();

    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withMax(['as max_votes' => $items], 'votes')
        ->withMin(['as min_votes' => $items], 'votes')
        ->toArray()['aggregates'];

    expect($aggregates['max_votes'])->toBe(5)
        ->and($aggregates['min_votes'])->toBe(2);
});

it('withAvg with Enumerable constraint returns correct average', function (): void {
    $items = Comment::query()->get(); // votes: 3, 5, 2 → avg = 10/3

    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withAvg(['as avg_votes' => $items], 'votes')
        ->toArray()['aggregates'];

    expect($aggregates['avg_votes'])->toEqualWithDelta(10 / 3, 0.001);
});

it('withExists with Enumerable constraint returns true for non-empty, false for empty', function (): void {
    $items = Comment::query()->get();
    $empty = $items->where('votes', '>', 100); // no items

    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withExists(['as has_any' => $items])
        ->withExists(['as has_none' => $empty])
        ->toArray()['aggregates'];

    expect($aggregates['has_any'])->toBeTrue()
        ->and($aggregates['has_none'])->toBeFalse();
});

it('empty Enumerable returns zero-value aggregates', function (): void {
    $empty = Comment::query()->get()->where('votes', '>', 1000);

    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withSum(['as sum' => $empty], 'votes')
        ->withCount(['as count' => $empty])
        ->withMax(['as max' => $empty], 'votes')
        ->withAvg(['as avg' => $empty], 'votes')
        ->withExists(['as exists' => $empty])
        ->toArray()['aggregates'];

    expect($aggregates['sum'])->toBe(0)
        ->and($aggregates['count'])->toBe(0)
        ->and($aggregates['max'])->toBeNull()
        ->and($aggregates['avg'])->toBeNull()
        ->and($aggregates['exists'])->toBeFalse();
});

it('Enumerable constraint works alongside pre-known total on a single page', function (): void {
    $items = Comment::query()->get();

    DB::enableQueryLog();

    $aggregates = Comment::query()
        ->lazyPaginate(15, total: 3)
        ->withSum(['as high_sum' => $items->where('votes', '>', 3)], 'votes')
        ->withSum('votes')  // unconstrained — collection-computable on single page
        ->toArray()['aggregates'];

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // All unconstrained aggregates come from collection (total:3 + fits on page).
    // Enumerable-constrained aggregate also comes from collection. 0 aggregate DB queries.
    // Only the paginate SELECT fires (1 query).
    expect($queries)->toHaveCount(1)
        ->and($aggregates['high_sum'])->toBe(5)
        ->and($aggregates['sum_votes'])->toBe(10);
});

it('Closure constraint still fires a DB query', function (): void {
    DB::enableQueryLog();

    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withSum(['as high_sum' => fn ($q) => $q->where('votes', '>', 3)], 'votes')
        ->toArray()['aggregates'];

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Closure constraint → DB query (CROSS JOIN with paginator total) + paginate SELECT.
    expect($queries)->toHaveCount(2)
        ->and($aggregates['high_sum'])->toBe(5);
});

it('null constraint (unconstrained) still works as before', function (): void {
    $aggregates = Comment::query()
        ->lazyPaginate(15)
        ->withSum('votes')
        ->toArray()['aggregates'];

    expect($aggregates['sum_votes'])->toBe(10);
});
