<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
    });

    Schema::create('comments', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('post_id');
        $table->string('content');
        $table->integer('votes');
    });

    $postOne = Post::query()->create(['title' => 'First']);
    $postTwo = Post::query()->create(['title' => 'Second']);

    Comment::query()->create(['post_id' => $postOne->id, 'content' => 'code is great', 'votes' => 3]);
    Comment::query()->create(['post_id' => $postOne->id, 'content' => 'hello world', 'votes' => 5]);
    Comment::query()->create(['post_id' => $postTwo->id, 'content' => 'code sample', 'votes' => 2]);
});

it('adds aggregate meta to length-aware pagination', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalCountOf(['comments' => function (Builder $query): void {
            $query->where('content', 'like', 'code%');
        }])
        ->withTotalSumOf('comments as total_votes', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toMatchArray([
        'comments_count' => 2,
        'total_votes' => 10,
    ]);
});

it('adds aggregate meta to simple pagination', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->simplePaginateWithTotals(1)
        ->withTotalCountOf('comments');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_count'])->toBe(3);
});

it('adds aggregate meta to cursor pagination', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->cursorPaginateWithTotals(1)
        ->withTotalExistsOf('comments');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_exists'])->toBeTrue();
});

it('returns total row count with direct withTotalCount', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalCount();

    $payload = $paginator->toArray();

    expect($payload['aggregates']['total_count'])->toBe(2);
});

it('returns max column value with direct withTotalMax', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalMax('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['total_max_votes'])->toEqual(5);
});

it('returns sum of column values with direct withTotalSum', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalSum('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['total_sum_votes'])->toEqual(10);
});

it('returns bool existence with direct withTotalExists', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalExists();

    $payload = $paginator->toArray();

    expect($payload['aggregates']['total_exists'])->toBeTrue();
});

it('supports column alias on direct withTotalMax', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalMax('votes as top_vote');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('top_vote')
        ->and($payload['aggregates']['top_vote'])->toEqual(5);
});

it('supports closure constraint on direct withTotalCount', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalCount('*', fn (Builder $q) => $q->where('votes', '>', 3));

    $payload = $paginator->toArray();

    expect($payload['aggregates']['total_count'])->toBe(1);
});

it('supports chaining two withTotalMax calls with different aliases', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalMax('votes as top_recent_vote', fn (Builder $q) => $q->where('votes', '>', 2))
        ->withTotalMax('votes as top_alltime_vote');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])
        ->toHaveKey('top_recent_vote')
        ->toHaveKey('top_alltime_vote')
        ->and($payload['aggregates']['top_recent_vote'])->toEqual(5)
        ->and($payload['aggregates']['top_alltime_vote'])->toEqual(5);
});

it('supports column alias and closure together on direct withTotalMax', function (): void {
    // withTotalMax('column as alias', closure) — alias names the result key,
    // closure scopes the query before the aggregate is executed.
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(1)
        ->withTotalMax('votes as top_recent_vote', fn (Builder $q) => $q->where('votes', '>', 2));

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('top_recent_vote')
        ->and($payload['aggregates']['top_recent_vote'])->toEqual(5);
});

it('computes true global average with withTotalAvgOf, not average-of-averages', function (): void {
    // Post 1 has votes [3, 5] → per-model avg = 4
    // Post 2 has votes [2]   → per-model avg = 2
    // Average-of-averages (wrong): (4 + 2) / 2 = 3.0
    // True global average (correct): (3 + 5 + 2) / 3 ≈ 3.333...
    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(15)
        ->withTotalAvgOf('comments', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_avg_votes'])->toEqualWithDelta(10 / 3, 0.001);
});

it('returns correct min with withTotalMinOf', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(15)
        ->withTotalMinOf('comments', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_min_votes'])->toBe(2);
});

it('returns null/zero defaults for relation aggregates when result set is empty', function (): void {
    Post::query()->delete();
    Comment::query()->delete();

    $paginator = Post::query()
        ->paginateWithTotals(15)
        ->withTotalCountOf('comments')
        ->withTotalSumOf('comments', 'votes')
        ->withTotalAvgOf('comments', 'votes')
        ->withTotalMaxOf('comments', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toMatchArray([
        'comments_count' => 0,
        'comments_sum_votes' => 0,
        'comments_avg_votes' => null,
        'comments_max_votes' => null,
    ]);
});

it('withPageCount returns count of items on the current page', function (): void {
    // 3 comments total, page 1 of 1 (perPage=10)
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageCount();

    $payload = $paginator->toArray();

    expect($payload['aggregates']['page_count'])->toBe(3);
});

it('withPageCount with filter closure counts matching items on the page', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageCount('* as high_votes', fn (Comment $c): bool => $c->votes > 3);

    $payload = $paginator->toArray();

    // Only the comment with votes=5 matches
    expect($payload['aggregates']['high_votes'])->toBe(1);
});

it('withPageSum computes sum from page items', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageSum('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['page_sum_votes'])->toEqual(10);
});

it('withPageMax computes max from page items', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageMax('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['page_max_votes'])->toEqual(5);
});

it('withPageMin computes min from page items', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageMin('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['page_min_votes'])->toEqual(2);
});

it('withPageAvg computes avg from page items', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageAvg('votes');

    $payload = $paginator->toArray();

    // (3 + 5 + 2) / 3 ≈ 3.333
    expect($payload['aggregates']['page_avg_votes'])->toEqualWithDelta(10 / 3, 0.001);
});

it('withPageExists returns bool', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageExists();

    $payload = $paginator->toArray();

    expect($payload['aggregates']['page_exists'])->toBeTrue()->toBeBool();
});

it('withPageExists returns false when page is empty', function (): void {
    Comment::query()->delete();

    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageExists();

    $payload = $paginator->toArray();

    expect($payload['aggregates']['page_exists'])->toBeFalse()->toBeBool();
});

it('supports column alias on withPageMax', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(10)
        ->withPageMax('votes as top');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('top')
        ->and($payload['aggregates']['top'])->toEqual(5);
});

it('page and full-set totals coexist in the same aggregates key', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->paginateWithTotals(1)  // page 1, only 1 item (votes=3)
        ->withPageSum('votes')
        ->withTotalSum('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('page_sum_votes')
        ->toHaveKey('total_sum_votes')
        ->and($payload['aggregates']['page_sum_votes'])->toEqual(3)   // only page 1 item
        ->and($payload['aggregates']['total_sum_votes'])->toEqual(10); // all items
});

it('withTotalCount on LengthAwarePaginator reuses total without extra query', function (): void {
    \Illuminate\Support\Facades\DB::enableQueryLog();

    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(15)
        ->withTotalCount();

    $paginator->toArray();

    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    // paginateWithTotals fires 2 queries (count + select); withTotalCount should add none
    expect(count($queries))->toBe(2)
        ->and($paginator->toArray()['aggregates']['total_count'])->toBe(2);
});

it('withTotalExists on LengthAwarePaginator reuses total without extra query', function (): void {
    \Illuminate\Support\Facades\DB::enableQueryLog();

    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(15)
        ->withTotalExists();

    $paginator->toArray();

    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect(count($queries))->toBe(2)
        ->and($paginator->toArray()['aggregates']['total_exists'])->toBeTrue();
});

it('withTotalExistsOf returns bool, matching withTotalExists return type', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->paginateWithTotals(15)
        ->withTotalExists()
        ->withTotalExistsOf('comments');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['total_exists'])->toBeTrue()
        ->and($payload['aggregates']['comments_exists'])->toBeTrue()
        ->and($payload['aggregates']['total_exists'])->toBeBool()
        ->and($payload['aggregates']['comments_exists'])->toBeBool();
});

class Post extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}

class Comment extends Model
{
    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
