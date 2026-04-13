<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Note: Post and Comment model classes are defined in AggregatesPaginatorTest.php.
// Pest loads test files alphabetically, so AggregatesPaginatorTest loads before SqlTest,
// making those classes available here without redefinition.

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

    return array_map(fn (array $entry): string => array_reduce(
        $entry['bindings'],
        fn (string $sql, mixed $binding): string => (string) preg_replace(
            '/\?/',
            is_string($binding) ? sprintf("'%s'", $binding) : (string) $binding,
            $sql,
            1,
        ),
        $entry['query'],
    ), $log);
}

// ─── Single base aggregate ────────────────────────────────────────────────────

it('single base withCount fires a scalar COUNT and a paginate query', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount()->toArray()
    );

    expect($queries)->toBe([
        'select count(*) as aggregate from "posts"',
        'select * from "posts" limit 5 offset 0',
    ]);
});

it('single base withSum is batched with the paginator total into one CROSS JOIN, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => Comment::query()->lazyPaginate(5)->withSum('votes')->toArray()
    );

    // Only 2 queries: CROSS JOIN (SUM + injected total) + data page — no separate COUNT(*)
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select "comments"."id", "agg_comments"."sum_votes", "agg_comments"."__paginator_total" from "comments" cross join (select SUM("votes") AS "sum_votes", COUNT(*) AS "__paginator_total" from "comments") as "agg_comments"'
        );
});

// ─── Multiple base aggregates (same constraint) — CROSS JOIN ─────────────────

it('multiple base aggregates and the paginator total are batched into a single CROSS JOIN', function (): void {
    $queries = captureQueries(
        fn () => Comment::query()->lazyPaginate(5)->withMax('votes')->withMin('votes')->toArray()
    );

    // 2 queries: CROSS JOIN (MAX + MIN + injected total) + data page
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select "comments"."id", "agg_comments"."max_votes", "agg_comments"."min_votes", "agg_comments"."__paginator_total" from "comments" cross join (select MAX("votes") AS "max_votes", MIN("votes") AS "min_votes", COUNT(*) AS "__paginator_total" from "comments") as "agg_comments"'
        );
});

it('withCount paired with withExists batches both into one CROSS JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount()->withExists()->toArray()
    );

    // withCount reused as total — no separate COUNT(*) paginator query
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select "posts"."id", "agg_posts"."count", "agg_posts"."_agg_ecnt_exists" from "posts" cross join (select COUNT(*) AS "count", COUNT(*) AS "_agg_ecnt_exists" from "posts") as "agg_posts"'
        );
});

// ─── Constrained base aggregates ─────────────────────────────────────────────

it('constrained base aggregates with different constraints produce separate CROSS JOINs', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount([
            'as a' => fn (Builder $builder) => $builder->where('id', 1),
            'as b' => fn (Builder $builder) => $builder->where('id', 2),
        ])->toArray()
    );

    // __paginator_total is injected alongside the constrained aggregates in a third CROSS JOIN
    expect($queries[0])->toBe(
        'select "posts"."id", "agg_posts"."a", "agg_posts_2"."b", "agg_posts_3"."__paginator_total" from "posts" cross join (select COUNT(*) AS "a" from "posts" where "id" = 1) as "agg_posts" cross join (select COUNT(*) AS "b" from "posts" where "id" = 2) as "agg_posts_2" cross join (select COUNT(*) AS "__paginator_total" from "posts") as "agg_posts_3"'
    );
});

it('constrained base aggregates sharing the same constraint are batched into one CROSS JOIN', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount([
            'as a' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ])->withMax([
            'as b' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ], 'id')->toArray()
    );

    // __paginator_total is injected in a separate CROSS JOIN (different constraint = different group)
    expect($queries[0])->toBe(
        'select "posts"."id", "agg_posts"."a", "agg_posts"."b", "agg_posts_2"."__paginator_total" from "posts" cross join (select COUNT(*) AS "a", MAX("id") AS "b" from "posts" where "id" > 0) as "agg_posts" cross join (select COUNT(*) AS "__paginator_total" from "posts") as "agg_posts_2"'
    );
});

// ─── HasOneOrMany relation — LEFT JOIN ───────────────────────────────────────

it('relation aggregates for the same relation are batched into one LEFT JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount('comments')->withMax('comments', 'votes')->toArray()
    );

    expect($queries[0])->toBe(
        'select "posts"."id", "agg_comments"."comments_count", "agg_comments"."comments_max_votes" from "posts" left join (select post_id, COUNT(*) AS "comments_count", MAX("votes") AS "comments_max_votes" from "comments" group by "post_id") as "agg_comments" on "agg_comments"."post_id" = "posts"."id"'
    );
});

it('constrained relation aggregate applies WHERE inside the LEFT JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)
            ->withCount(['comments as high_votes' => fn (Builder $builder) => $builder->where('votes', '>', 3)])
            ->toArray()
    );

    expect($queries[0])->toBe(
        'select "posts"."id", "agg_comments"."high_votes" from "posts" left join (select post_id, COUNT(*) AS "high_votes" from "comments" where "votes" > 3 group by "post_id") as "agg_comments" on "agg_comments"."post_id" = "posts"."id"'
    );
});

// ─── withCount() total optimisation ──────────────────────────────────────────

it('withCount reuses the aggregate as the LengthAwarePaginator total, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount()->toArray()
    );

    // Only 2 queries: scalar COUNT (reused as total) + SELECT data page
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe('select count(*) as aggregate from "posts"')
        ->and($queries[1])->toBe('select * from "posts" limit 5 offset 0');
});

it('unconstrained base aggregate is batched with the paginator total, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withMax('id')->toArray()
    );

    // 2 queries: CROSS JOIN (MAX + injected total) + data page
    expect($queries)->toHaveCount(2);
});

it('withCount on a relation does not save the COUNT total query — three queries fire', function (): void {
    // Only base withCount() (no args) may substitute the paginator total.
    // A relation withCount('comments') must not be used as the total.
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount('comments')->toArray()
    );

    expect($queries)->toHaveCount(3);
});

it('single constrained base aggregate is batched with __paginator_total in a CROSS JOIN', function (): void {
    // __paginator_total is injected alongside the constrained aggregate → 2-instruction CROSS JOIN.
    $queries = captureQueries(
        fn () => Post::query()->lazyPaginate(5)->withCount([
            'as a_count' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ])->toArray()
    );

    // Query 1: CROSS JOIN with constrained aggregate + injected __paginator_total
    // Query 2: paginated data
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select "posts"."id", "agg_posts"."a_count", "agg_posts_2"."__paginator_total" from "posts" cross join (select COUNT(*) AS "a_count" from "posts" where "id" > 0) as "agg_posts" cross join (select COUNT(*) AS "__paginator_total" from "posts") as "agg_posts_2"'
        );
});
