<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('sql_posts', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('title');
    });

    Schema::create('sql_comments', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('sql_post_id');
        $blueprint->integer('votes');
    });

    $sqlPost = SqlPost::query()->create(['title' => 'A']);
    $postB = SqlPost::query()->create(['title' => 'B']);
    SqlComment::query()->create(['sql_post_id' => $sqlPost->id, 'votes' => 3]);
    SqlComment::query()->create(['sql_post_id' => $sqlPost->id, 'votes' => 5]);
    SqlComment::query()->create(['sql_post_id' => $postB->id, 'votes' => 2]);
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
        fn () => SqlPost::query()->lazyPaginate(5)->withCount()->toArray()
    );

    expect($queries)->toBe([
        'select count(*) as aggregate from "sql_posts"',
        'select * from "sql_posts" limit 5 offset 0',
    ]);
});

it('single base withSum is batched with the paginator total into one CROSS JOIN, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => SqlComment::query()->lazyPaginate(5)->withSum('votes')->toArray()
    );

    // Only 2 queries: CROSS JOIN (SUM + injected total) + data page — no separate COUNT(*)
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select "sql_comments"."id", "agg_sql_comments"."sum_votes", "agg_sql_comments"."__paginator_total" from "sql_comments" cross join (select SUM("votes") AS "sum_votes", COUNT(*) AS "__paginator_total" from "sql_comments") as "agg_sql_comments"'
        );
});

// ─── Multiple base aggregates (same constraint) — CROSS JOIN ─────────────────

it('multiple base aggregates and the paginator total are batched into a single CROSS JOIN', function (): void {
    $queries = captureQueries(
        fn () => SqlComment::query()->lazyPaginate(5)->withMax('votes')->withMin('votes')->toArray()
    );

    // 2 queries: CROSS JOIN (MAX + MIN + injected total) + data page
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select "sql_comments"."id", "agg_sql_comments"."max_votes", "agg_sql_comments"."min_votes", "agg_sql_comments"."__paginator_total" from "sql_comments" cross join (select MAX("votes") AS "max_votes", MIN("votes") AS "min_votes", COUNT(*) AS "__paginator_total" from "sql_comments") as "agg_sql_comments"'
        );
});

it('withCount paired with withExists batches both into one CROSS JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withCount()->withExists()->toArray()
    );

    // withCount reused as total — no separate COUNT(*) paginator query
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe(
            'select "sql_posts"."id", "agg_sql_posts"."count", "agg_sql_posts"."_agg_ecnt_exists" from "sql_posts" cross join (select COUNT(*) AS "count", COUNT(*) AS "_agg_ecnt_exists" from "sql_posts") as "agg_sql_posts"'
        );
});

// ─── Constrained base aggregates ─────────────────────────────────────────────

it('constrained base aggregates with different constraints produce separate CROSS JOINs', function (): void {
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withCount([
            'as a' => fn (Builder $builder) => $builder->where('id', 1),
            'as b' => fn (Builder $builder) => $builder->where('id', 2),
        ])->toArray()
    );

    expect($queries[0])->toBe(
        'select "sql_posts"."id", "agg_sql_posts"."a", "agg_sql_posts_2"."b" from "sql_posts" cross join (select COUNT(*) AS "a" from "sql_posts" where "id" = 1) as "agg_sql_posts" cross join (select COUNT(*) AS "b" from "sql_posts" where "id" = 2) as "agg_sql_posts_2"'
    );
});

it('constrained base aggregates sharing the same constraint are batched into one CROSS JOIN', function (): void {
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withCount([
            'as a' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ])->withMax([
            'as b' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ], 'id')->toArray()
    );

    expect($queries[0])->toBe(
        'select "sql_posts"."id", "agg_sql_posts"."a", "agg_sql_posts"."b" from "sql_posts" cross join (select COUNT(*) AS "a", MAX("id") AS "b" from "sql_posts" where "id" > 0) as "agg_sql_posts"'
    );
});

// ─── HasOneOrMany relation — LEFT JOIN ───────────────────────────────────────

it('relation aggregates for the same relation are batched into one LEFT JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withCount('sqlComments')->withMax('sqlComments', 'votes')->toArray()
    );

    expect($queries[0])->toBe(
        'select "sql_posts"."id", "agg_sqlComments"."sql_comments_count", "agg_sqlComments"."sql_comments_max_votes" from "sql_posts" left join (select sql_post_id, COUNT(*) AS "sql_comments_count", MAX("votes") AS "sql_comments_max_votes" from "sql_comments" group by "sql_post_id") as "agg_sqlComments" on "agg_sqlComments"."sql_post_id" = "sql_posts"."id"'
    );
});

it('constrained relation aggregate applies WHERE inside the LEFT JOIN derived table', function (): void {
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)
            ->withCount(['sqlComments as high_votes' => fn (Builder $builder) => $builder->where('votes', '>', 3)])
            ->toArray()
    );

    expect($queries[0])->toBe(
        'select "sql_posts"."id", "agg_sqlComments"."high_votes" from "sql_posts" left join (select sql_post_id, COUNT(*) AS "high_votes" from "sql_comments" where "votes" > 3 group by "sql_post_id") as "agg_sqlComments" on "agg_sqlComments"."sql_post_id" = "sql_posts"."id"'
    );
});

// ─── withCount() total optimisation ──────────────────────────────────────────

it('withCount reuses the aggregate as the LengthAwarePaginator total, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withCount()->toArray()
    );

    // Only 2 queries: scalar COUNT (reused as total) + SELECT data page
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBe('select count(*) as aggregate from "sql_posts"')
        ->and($queries[1])->toBe('select * from "sql_posts" limit 5 offset 0');
});

it('unconstrained base aggregate is batched with the paginator total, saving a COUNT query', function (): void {
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withMax('id')->toArray()
    );

    // 2 queries: CROSS JOIN (MAX + injected total) + data page
    expect($queries)->toHaveCount(2);
});

it('withCount on a relation does not save the COUNT total query — three queries fire', function (): void {
    // Only base withCount() (no args) may substitute the paginator total.
    // A relation withCount('sqlComments') must not be used as the total.
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withCount('sqlComments')->toArray()
    );

    expect($queries)->toHaveCount(3);
});

it('single constrained base aggregate fires a scalar query, not a CROSS JOIN', function (): void {
    // A single constrained base aggregate still uses the scalar path (resolveSingleInstruction).
    $queries = captureQueries(
        fn () => SqlPost::query()->lazyPaginate(5)->withCount([
            'as a_count' => fn (Builder $builder) => $builder->where('id', '>', 0),
        ])->toArray()
    );

    // Query 1: scalar COUNT with WHERE applied
    // Query 2: COUNT(*) for paginator total (constrained count must not replace it)
    // Query 3: paginated data
    expect($queries)->toHaveCount(3)
        ->and($queries[0])->toBe('select count(*) as aggregate from "sql_posts" where "id" > 0');
});

// ─── Models ───────────────────────────────────────────────────────────────────

class SqlPost extends Model
{
    protected $table = 'sql_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function sqlComments(): HasMany
    {
        return $this->hasMany(SqlComment::class);
    }
}

class SqlComment extends Model
{
    protected $table = 'sql_comments';

    protected $guarded = [];

    public $timestamps = false;
}
