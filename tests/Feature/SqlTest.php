<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Post;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('posts', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('title');
    });

    Schema::create('comments', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('post_id');
        $blueprint->integer('votes');
    });

    $post = Post::query()->create(['title' => 'A']);
    $postB = Post::query()->create(['title' => 'B']);
    Comment::query()->create(['post_id' => $post->id, 'votes' => 3]);
    Comment::query()->create(['post_id' => $post->id, 'votes' => 5]);
    Comment::query()->create(['post_id' => $postB->id, 'votes' => 2]);
});

/**
 * Capture queries fired during $fn(), substituting bindings into placeholders.
 *
 * @return list<string>
 */
function captureQueries(Closure $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Snapshots are written in SQLite/PostgreSQL quoting: normalise MySQL backticks, and
    // Laravel 13's quoted `aggregate` alias, so the same snapshots hold on every driver and version.
    return array_map(fn (array $entry): string => str_replace(['`', 'as "aggregate"'], ['"', 'as aggregate'], array_reduce(
        $entry['bindings'],
        fn (string $sql, mixed $binding): string => (string) preg_replace(
            '/\?/',
            is_string($binding) ? sprintf("'%s'", $binding) : (string) $binding,
            $sql,
            1,
        ),
        $entry['query'],
    )), $log);
}

// ─── Single base aggregate ────────────────────────────────────────────────────

it('single base withAggCount fires a scalar COUNT and a paginate query', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggCount()->paginateWithAggregates(5)->toArray()
    );

    expect($queries)->toBe([
        'select count(*) as aggregate from "posts"',
        'select * from "posts" limit 5 offset 0',
    ]);
});

it('single base withAggSum is batched with the paginator total into one CROSS JOIN, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => Comment::query()->withAggSum('votes')->paginateWithAggregates(5)->toArray()
    );

    // Only 2 queries: CROSS JOIN (SUM + injected total) + data page — no separate COUNT(*)
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select SUM("votes") AS "sum_votes", COUNT(*) AS "__paginator_total" from "comments" limit 1'
        );
});

// ─── Multiple base aggregates (same constraint) — CROSS JOIN ─────────────────

it('multiple base aggregates and the paginator total are batched into a single CROSS JOIN', function (): void {
    $queries = captureQueries(
        fn () => Comment::query()->withAggMax('votes')->withAggMin('votes')->paginateWithAggregates(5)->toArray()
    );

    // 2 queries: CROSS JOIN (MAX + MIN + injected total) + data page
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select MAX("votes") AS "max_votes", MIN("votes") AS "min_votes", COUNT(*) AS "__paginator_total" from "comments" limit 1'
        );
});

it('withAggCount paired with withAggExists batches both into one CROSS JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggCount()->withAggExists()->paginateWithAggregates(5)->toArray()
    );

    // withAggCount reused as total — no separate COUNT(*) paginator query
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select COUNT(*) AS "count", COUNT(*) AS "_agg_ecnt_exists" from "posts" limit 1'
        );
});

// ─── Constrained base aggregates ─────────────────────────────────────────────

it('constrained base aggregates with different constraints produce separate CROSS JOINs', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggCount([
            'as a' => fn (Builder $builder) => $builder->where('id', 1),
            'as b' => fn (Builder $builder) => $builder->where('id', 2),
        ])->paginateWithAggregates(5)->toArray()
    );

    expect($queries[0])->toBe(
        'select * from (select COUNT(*) AS "a" from "posts" where "id" = 1) as "agg_posts" cross join (select COUNT(*) AS "b" from "posts" where "id" = 2) as "agg_posts_2" limit 1'
    );
});

it('constrained base aggregates sharing the same constraint are batched into one CROSS JOIN', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggCount([
            'as a' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ])->withAggMax([
            'as b' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ], 'id')->paginateWithAggregates(5)->toArray()
    );

    expect($queries[0])->toBe(
        'select COUNT(*) AS "a", MAX("id") AS "b" from "posts" where "id" > 0 limit 1'
    );
});

// ─── HasOneOrMany relation — LEFT JOIN ───────────────────────────────────────

it('relation aggregates for the same relation are batched into one LEFT JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggCount('comments')->withAggMax('comments', 'votes')->paginateWithAggregates(5)->toArray()
    );

    expect($queries[0])->toBe(
        'select COUNT(*) AS "comments_count", MAX("votes") AS "comments_max_votes" from "comments" where "comments"."post_id" in (select "posts"."id" from "posts") limit 1'
    );
});

it('constrained relation aggregate applies WHERE inside the LEFT JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggCount(['comments as high_votes' => fn (Builder $builder) => $builder->where('votes', '>', 3)])
            ->paginateWithAggregates(5)
            ->toArray()
    );

    expect($queries[0])->toBe(
        'select COUNT(*) AS "high_votes" from "comments" where "votes" > 3 and "comments"."post_id" in (select "posts"."id" from "posts") limit 1'
    );
});

// ─── withAggCount() total optimisation ──────────────────────────────────────────

it('withAggCount reuses the aggregate as the LengthAwarePaginator total, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggCount()->paginateWithAggregates(5)->toArray()
    );

    // Only 2 queries: scalar COUNT (reused as total) + SELECT data page
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe('select count(*) as aggregate from "posts"')
        ->and($queries[1])->toBe('select * from "posts" limit 5 offset 0');
});

it('unconstrained base aggregate is batched with the paginator total, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->withAggMax('id')->paginateWithAggregates(5)->toArray()
    );

    // 2 queries: CROSS JOIN (MAX + injected total) + data page
    expect($queries)->toHaveCount(2);
});

it('withAggCount on a relation does not save the COUNT total query — three queries fire', function (): void {
    // Only base withAggCount() (no args) may substitute the paginator total.
    // A relation withAggCount('comments') must not be used as the total.
    $queries = captureQueries(
        fn () => Post::query()->withAggCount('comments')->paginateWithAggregates(5)->toArray()
    );

    expect($queries)->toHaveCount(3);
});

it('single constrained base aggregate fires a scalar query, not a CROSS JOIN', function (): void {
    // A single constrained base aggregate still uses the scalar path (resolveSingleInstruction).
    $queries = captureQueries(
        fn () => Post::query()->withAggCount([
            'as a_count' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ])->paginateWithAggregates(5)->toArray()
    );

    // Query 1: scalar COUNT with WHERE applied
    // Query 2: COUNT(*) for paginator total (constrained count must not replace it)
    // Query 3: paginated data
    expect($queries)->toHaveCount(3)
        ->and($queries[0])->toBe('select count(*) as aggregate from "posts" where "id" > 0');
});
