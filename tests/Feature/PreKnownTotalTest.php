<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Note: Post and Comment model classes are defined in AggregatesPaginatorTest.php.
// Pest loads test files alphabetically, so AggregatesPaginatorTest loads before PreKnownTotalTest,
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

    Comment::query()->create(['post_id' => $postOne->id, 'content' => 'code is great', 'votes' => 3]);
    Comment::query()->create(['post_id' => $postOne->id, 'content' => 'hello world', 'votes' => 5]);
    Comment::query()->create(['post_id' => $postTwo->id, 'content' => 'code sample', 'votes' => 2]);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Run $callback, return the query log captured during its execution.
 *
 * @return array<int, array<string, mixed>>
 */
function captureQueriesFor(Closure $callback): array
{
    DB::enableQueryLog();
    $callback();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return $queries;
}

// ─── Query-count optimisation tests ──────────────────────────────────────────

it('pre-known total with no aggregates fires only the paginate SELECT', function (): void {
    $queries = captureQueriesFor(function (): void {
        Comment::query()->paginateWithAggregates(15, total: 3)->toArray();
    });

    expect($queries)->toHaveCount(1);
});

it('pre-known total that does not fit on one page skips COUNT injection but still fires aggregate query', function (): void {
    // 3 rows, 1 per page → does not fit. withSum still needs DB, but no __paginator_total injected.
    $queries = captureQueriesFor(function (): void {
        Comment::query()->paginateWithAggregates(1, total: 3)->withSum('votes')->toArray();
    });

    expect($queries)->toHaveCount(2);

    // The paginate SELECT fires first (index 0); the CROSS JOIN aggregate is index 1.
    $aggregateSql = $queries[1]['query'];
    expect($aggregateSql)->not->toContain('__paginator_total');
    expect(strtolower((string) $aggregateSql))->toContain('sum');
});

it('pre-known total that fits on one page fires only one query for purely collection-computable aggregates', function (): void {
    $queries = captureQueriesFor(function (): void {
        Comment::query()
            ->paginateWithAggregates(15, total: 3)
            ->withSum('votes')
            ->withMax('votes')
            ->withMin('votes')
            ->withAvg('votes')
            ->withCount()
            ->withExists()
            ->toArray();
    });

    expect($queries)->toHaveCount(1);
});

it('Expression column aggregate falls back to DB even when fits on one page', function (): void {
    $queries = captureQueriesFor(function (): void {
        Comment::query()
            ->paginateWithAggregates(15, total: 3)
            ->withMax(DB::raw('"votes"'))
            ->toArray();
    });

    expect($queries)->toHaveCount(2);
});

it('constrained base aggregate still fires DB query when fits on one page', function (): void {
    $queries = captureQueriesFor(function (): void {
        Comment::query()
            ->paginateWithAggregates(15, total: 3)
            ->withSum(['as high_sum' => fn ($b) => $b->where('votes', '>', 3)], 'votes')
            ->toArray();
    });

    expect($queries)->toHaveCount(2);
});

it('relation aggregate still fires DB query when fits on one page', function (): void {
    $queries = captureQueriesFor(function (): void {
        Post::query()
            ->paginateWithAggregates(15, total: 2)
            ->withCount('comments')
            ->toArray();
    });

    expect($queries)->toHaveCount(2);
});

it('mix of collection-computable and relation aggregate fires DB, collection overrides computable result', function (): void {
    $queries = captureQueriesFor(function (): void {
        Post::query()
            ->paginateWithAggregates(15, total: 2)
            ->withSum('id')
            ->withCount('comments')
            ->toArray();
    });

    // Coordinator fires for both (2 instructions), then collection overrides the base sum.
    expect($queries)->toHaveCount(2);
});

it('total=0 fires no DB queries — Laravel skips the SELECT for empty results', function (): void {
    Comment::query()->delete();

    $queries = captureQueriesFor(function (): void {
        Comment::query()
            ->paginateWithAggregates(15, total: 0)
            ->withSum('votes')
            ->withMax('votes')
            ->withCount()
            ->withExists()
            ->toArray();
    });

    // Laravel's Builder::paginate() skips the SELECT entirely when $total === 0.
    // All aggregates are computed from the empty Collection with no DB round-trips.
    expect($queries)->toHaveCount(0);
});

it('total exactly equal to perPage uses the collection path (boundary)', function (): void {
    // 3 comments, perPage=3, total=3 → 3 <= 3 → fits.
    $queries = captureQueriesFor(function (): void {
        Comment::query()->paginateWithAggregates(3, total: 3)->withSum('votes')->toArray();
    });

    expect($queries)->toHaveCount(1);
});

it('restricted columns with fits-on-one-page merges aggregate column into SELECT', function (): void {
    $queries = captureQueriesFor(function (): void {
        Comment::query()->paginateWithAggregates(15, ['id'], total: 3)->withSum('votes')->toArray();
    });

    // Only 1 query (paginate SELECT), and that query must include the votes column.
    expect($queries)->toHaveCount(1);
    expect($queries[0]['query'])->toContain('"votes"');
});

it('without pre-known total the original CROSS JOIN behaviour is unchanged', function (): void {
    $queries = captureQueriesFor(function (): void {
        Comment::query()->paginateWithAggregates(5)->withSum('votes')->toArray();
    });

    // CROSS JOIN aggregate (with __paginator_total) + paginate SELECT = 2 queries.
    expect($queries)->toHaveCount(2);
    expect($queries[0]['query'])->toContain('__paginator_total');
});

// ─── Correctness / value tests ────────────────────────────────────────────────

it('collection-computed aggregates return correct values when fits on one page', function (): void {
    // Votes: 3, 5, 2 → sum=10, max=5, min=2, avg=10/3, count=3, exists=true
    $payload = Comment::query()
        ->paginateWithAggregates(15, total: 3)
        ->withSum('votes')
        ->withMax('votes')
        ->withMin('votes')
        ->withAvg('votes')
        ->withCount()
        ->withExists()
        ->toArray();

    expect($payload['aggregates']['sum_votes'])->toBe(10)
        ->and($payload['aggregates']['max_votes'])->toBe(5)
        ->and($payload['aggregates']['min_votes'])->toBe(2)
        ->and($payload['aggregates']['avg_votes'])->toEqualWithDelta(10 / 3, 0.001)
        ->and($payload['aggregates']['count'])->toBe(3)
        ->and($payload['aggregates']['exists'])->toBeTrue();
});

it('collection-computed count on empty set returns zero', function (): void {
    Comment::query()->delete();

    $payload = Comment::query()
        ->paginateWithAggregates(15, total: 0)
        ->withCount()
        ->withExists()
        ->withSum('votes')
        ->withMax('votes')
        ->toArray();

    expect($payload['aggregates']['count'])->toBe(0)
        ->and($payload['aggregates']['exists'])->toBeFalse()
        ->and($payload['aggregates']['sum_votes'])->toBe(0)
        ->and($payload['aggregates']['max_votes'])->toBeNull();
});

it('pre-known total is reflected in toArray total and last_page', function (): void {
    $payload = Comment::query()->paginateWithAggregates(1, total: 3)->toArray();

    expect($payload['total'])->toBe(3)
        ->and($payload['last_page'])->toBe(3)
        ->and($payload['next_page_url'])->not->toBeNull();
});

it('__paginator_total alias is never exposed in aggregates when total is pre-known', function (): void {
    $payload = Comment::query()
        ->paginateWithAggregates(5, total: 3)
        ->withSum('votes')
        ->toArray();

    expect($payload['aggregates'])->not->toHaveKey('__paginator_total');
});

it('mix of collection-computable and relation aggregate returns correct values for both', function (): void {
    // Post 1 has comments with votes 3+5, Post 2 has votes 2. Sum of post IDs = 1+2 = 3.
    // total comments count = 3.
    $payload = Post::query()
        ->orderBy('id')
        ->paginateWithAggregates(15, total: 2)
        ->withSum('id')
        ->withCount('comments')
        ->toArray();

    expect($payload['aggregates']['sum_id'])->toBe(3)
        ->and($payload['aggregates']['comments_count'])->toBe(3);
});
