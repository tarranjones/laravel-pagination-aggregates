<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TarranJones\LaravelPaginationAggregates\AggregateInstruction;

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

    Schema::create('tags', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->integer('value');
    });

    Schema::create('post_tag', function (Blueprint $blueprint): void {
        $blueprint->foreignId('post_id');
        $blueprint->foreignId('tag_id');
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
        ->lazyPaginate(1)
        ->withSum('comments', 'votes')
        ->withMax('comments', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toMatchArray([
        'comments_sum_votes' => 10,
        'comments_max_votes' => 5,
    ]);
});

it('adds aggregate meta to simple pagination', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazySimplePaginate(10)
        ->withCount('comments');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_count'])->toBe(3);
});

it('adds aggregate meta to cursor pagination', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyCursorPaginate(10)
        ->withExists('comments');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_exists'])->toBeTrue();
});

it('supports column alias on withMax', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withMax('comments as top_vote', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('top_vote')
        ->and($payload['aggregates']['top_vote'])->toEqual(5);
});

it('supports closure constraint on withCount', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withCount(['comments' => fn (Builder $builder): Builder => $builder->where('votes', '>', 3)]);

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_count'])->toBe(1);
});

it('supports chaining two withMax calls with different aliases', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withMax(['comments as top_recent_vote' => fn (Builder $builder): Builder => $builder->where('votes', '>', 2)], 'votes')
        ->withMax('comments as top_alltime_vote', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])
        ->toHaveKey('top_recent_vote')
        ->toHaveKey('top_alltime_vote')
        ->and($payload['aggregates']['top_recent_vote'])->toEqual(5)
        ->and($payload['aggregates']['top_alltime_vote'])->toEqual(5);
});

it('supports column alias and closure together on withMax', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withMax(['comments as top_recent_vote' => fn (Builder $builder): Builder => $builder->where('votes', '>', 2)], 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('top_recent_vote')
        ->and($payload['aggregates']['top_recent_vote'])->toEqual(5);
});

it('withMax accepts an array alias and constraint', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withMax(['comments as peak_vote' => fn (Builder $builder): Builder => $builder->where('votes', '<', 5)], 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('peak_vote')
        ->and($payload['aggregates']['peak_vote'])->toEqual(3);
});

it('defers pagination queries until serialization', function (): void {
    DB::enableQueryLog();

    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withCount('comments');

    expect(DB::getQueryLog())->toBe([]);

    $paginator->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Query 1: relation aggregate (derived table)
    // Query 2: COUNT(*) for paginator total (no direct withCount() to reuse)
    // Query 3: paginated data
    expect(count($queries))->toBe(3);
});

it('constrained withCount does not replace the paginator total', function (): void {
    DB::enableQueryLog();

    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withCount(['as first_count' => fn (Builder $builder): Builder => $builder->where('title', 'First')]);

    $payload = $paginator->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Query 1: constrained count aggregate
    // Query 2: COUNT(*) for paginator total (must still fire — constrained count must not replace it)
    // Query 3: paginated data
    expect(count($queries))->toBe(3)
        ->and($payload['total'])->toBe(2)
        ->and($payload['aggregates']['first_count'])->toBe(1);
});

it('uses withCount() aggregate as the paginator total, skipping the COUNT(*) query', function (): void {
    DB::enableQueryLog();

    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withCount();

    $payload = $paginator->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Query 1: direct count aggregate (doubles as paginator total, skipping COUNT(*))
    // Query 2: paginated data
    expect(count($queries))->toBe(2)
        ->and($payload['total'])->toBe(2)
        ->and($payload['last_page'])->toBe(2)
        ->and($payload['aggregates']['count'])->toBe(2);
});

it('withMax and withMin together are combined into single derived table', function (): void {
    DB::enableQueryLog();

    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withMax('comments', 'votes')
        ->withMin('comments', 'votes');

    $result = $paginator->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Query 1: MAX + MIN batched into one derived table (not two separate queries)
    // Query 2: COUNT(*) for paginator total
    // Query 3: paginated data
    expect(count($queries))->toBe(3)
        ->and($result['aggregates']['comments_max_votes'])->toEqual(5)
        ->and($result['aggregates']['comments_min_votes'])->toEqual(2);
});

it('withMax, withMin, withSum, withAvg return all four numeric aggregates', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withMax('comments', 'votes')
        ->withMin('comments', 'votes')
        ->withSum('comments', 'votes')
        ->withAvg('comments', 'votes');

    $agg = $paginator->toArray()['aggregates'];

    expect($agg['comments_max_votes'])->toEqual(5)
        ->and($agg['comments_min_votes'])->toEqual(2)
        ->and($agg['comments_sum_votes'])->toEqual(10)
        ->and($agg['comments_avg_votes'])->toEqualWithDelta(10 / 3, 0.001);
});

it('withMax on empty table returns null', function (): void {
    Comment::query()->delete();
    Post::query()->delete();

    $paginator = Post::query()
        ->lazyPaginate(15)
        ->withMax('comments', 'votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['comments_max_votes'])->toBeNull();
});

it('AggregateInstruction throws for invalid function', function (): void {
    expect(fn (): AggregateInstruction => new AggregateInstruction('invalid', 'alias', '*', ['comments' => null]))
        ->toThrow(InvalidArgumentException::class);
});

it('AggregateInstruction throws when column is null for numeric functions', function (string $function): void {
    expect(fn (): AggregateInstruction => new AggregateInstruction($function, 'alias', null, ['comments' => null]))
        ->toThrow(InvalidArgumentException::class);
})->with(['max', 'min', 'sum', 'avg']);

it('withAvg on BelongsToMany returns correct global weighted average', function (): void {
    // Post 1 → tags with value 4, 6 (avg per-post = 5)
    // Post 2 → tags with value 2 (avg per-post = 2)
    // Global AVG() across all pivot-linked rows = (4 + 6 + 2) / 3 = 4.0
    $tagA = Tag::query()->create(['value' => 4]);
    $tagB = Tag::query()->create(['value' => 6]);
    $tagC = Tag::query()->create(['value' => 2]);

    $postOne = Post::query()->where('title', 'First')->first();
    $postTwo = Post::query()->where('title', 'Second')->first();

    $postOne->tags()->attach([$tagA->id, $tagB->id]);
    $postTwo->tags()->attach([$tagC->id]);

    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withAvg('tags', 'value');

    $agg = $paginator->toArray()['aggregates'];

    expect($agg['tags_avg_value'])->toEqualWithDelta(4.0, 0.001);
});

it('withAvg on BelongsToMany with a closure constraint averages only matching pivot rows', function (): void {
    // Tags: value 4, 6, 2. Constraint: value > 3 → only 4 and 6 qualify → avg = 5.0
    $tagA = Tag::query()->create(['value' => 4]);
    $tagB = Tag::query()->create(['value' => 6]);
    $tagC = Tag::query()->create(['value' => 2]);

    $postOne = Post::query()->where('title', 'First')->first();
    $postTwo = Post::query()->where('title', 'Second')->first();

    $postOne->tags()->attach([$tagA->id, $tagB->id]);
    $postTwo->tags()->attach([$tagC->id]);

    $agg = Post::query()
        ->lazyPaginate(10)
        ->withAvg(['tags as high_tag_avg' => fn (Builder $builder): Builder => $builder->where('value', '>', 3)], 'value')
        ->toArray()['aggregates'];

    expect($agg)->toHaveKey('high_tag_avg')
        ->and($agg['high_tag_avg'])->toEqualWithDelta(5.0, 0.001);
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

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
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

class Tag extends Model
{
    protected $table = 'tags';

    protected $guarded = [];

    public $timestamps = false;

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}

it('withCount with no parameters returns total count', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withCount();

    $payload = $paginator->toArray();

    expect($payload['aggregates']['count'])->toBe(2);
});

it('withMax with single parameter computes max on base query', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withMax('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['max_votes'])->toBe(5);
});

it('withMin with single parameter computes min on base query', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withMin('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['min_votes'])->toBe(2);
});

it('withSum with single parameter computes sum on base query', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withSum('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['sum_votes'])->toBe(10);
});

it('withAvg with single parameter computes avg on base query', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withAvg('votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['avg_votes'])->toEqualWithDelta(10 / 3, 0.001);
});

it('combines base query and relation aggregates', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withCount()
        ->withMax('comments', 'votes')
        ->withMax('id');

    $payload = $paginator->toArray();

    expect($payload['aggregates']['count'])->toBe(2)
        ->and($payload['aggregates']['comments_max_votes'])->toBe(5)
        ->and($payload['aggregates']['max_id'])->toBe(2);
});

it('supports custom alias on base query aggregate using "as" syntax', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withMax('id as max_post_id');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('max_post_id')
        ->and($payload['aggregates']['max_post_id'])->toBe(2);
});

it('supports custom alias on base query withCount using "as" syntax', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withSum('votes as total_votes');

    $payload = $paginator->toArray();

    expect($payload['aggregates'])->toHaveKey('total_votes')
        ->and($payload['aggregates']['total_votes'])->toBe(10);
});

// ─── Direct aggregate constraints & aliases ──────────────────────────────────

it('withCount constraint: only counts rows matching the constraint', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withCount(['as first_post_count' => fn (Builder $builder): Builder => $builder->where('title', 'First')]);

    expect($paginator->toArray()['aggregates']['first_post_count'])->toBe(1);
});

it('withSum constraint: only sums rows matching the constraint', function (): void {
    // Post 1 has comments with votes 3, 5. Post 2 has votes 2.
    // Only post_id = 1's comments → but this is a direct aggregate on Comment, not relation.
    // We paginate Comments and sum votes where votes > 3.
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withSum(['as high_votes' => fn (Builder $builder): Builder => $builder->where('votes', '>', 3)], 'votes');

    expect($paginator->toArray()['aggregates']['high_votes'])->toBe(5);
});

it('withMax constraint: only considers rows matching the constraint', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withMax(['as low_max' => fn (Builder $builder): Builder => $builder->where('votes', '<', 5)], 'votes');

    expect($paginator->toArray()['aggregates']['low_max'])->toBe(3);
});

it('withMin constraint: only considers rows matching the constraint', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withMin(['as high_min' => fn (Builder $builder): Builder => $builder->where('votes', '>', 2)], 'votes');

    expect($paginator->toArray()['aggregates']['high_min'])->toBe(3);
});

it('withAvg constraint: only averages rows matching the constraint', function (): void {
    // votes where votes >= 3: 3, 5 → avg = 4.0
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withAvg(['as avg_high' => fn (Builder $builder): Builder => $builder->where('votes', '>=', 3)], 'votes');

    expect($paginator->toArray()['aggregates']['avg_high'])->toEqualWithDelta(4.0, 0.001);
});

it('withExists constraint: returns true when matching rows exist', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withExists(['as has_high_votes' => fn (Builder $builder): Builder => $builder->where('votes', '>', 4)]);

    expect($paginator->toArray()['aggregates']['has_high_votes'])->toBeTrue();
});

it('withExists constraint: returns false when no rows match', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withExists(['as has_huge_votes' => fn (Builder $builder): Builder => $builder->where('votes', '>', 100)]);

    expect($paginator->toArray()['aggregates']['has_huge_votes'])->toBeFalse();
});

it('withCount array form supports multiple base aggregates with constraints', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withCount([
            'as high_count' => fn (Builder $builder): Builder => $builder->where('votes', '>', 3),
            'as low_count' => fn (Builder $builder): Builder => $builder->where('votes', '<=', 3),
        ]);

    $aggregates = $paginator->toArray()['aggregates'];

    // votes: 3, 5, 2 → high (>3): only 5 → count=1; low (<=3): 3 and 2 → count=2
    expect($aggregates['high_count'])->toBe(1)
        ->and($aggregates['low_count'])->toBe(2);
});

it('multiple direct aggregates with different constraints produce correct independent results', function (): void {
    $paginator = Comment::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withCount(['as high_count' => fn (Builder $builder): Builder => $builder->where('votes', '>', 3)])
        ->withSum(['as high_sum' => fn (Builder $builder): Builder => $builder->where('votes', '>', 3)], 'votes')
        ->withCount(['as low_count' => fn (Builder $builder): Builder => $builder->where('votes', '<=', 3)]);

    $aggregates = $paginator->toArray()['aggregates'];

    // votes: 3, 5, 2 → high (>3): only 5 → count=1, sum=5; low (<=3): 3 and 2 → count=2
    expect($aggregates['high_count'])->toBe(1)
        ->and($aggregates['high_sum'])->toBe(5)
        ->and($aggregates['low_count'])->toBe(2);
});

it('direct constrained aggregates and relation aggregates can be combined', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withCount()                                         // all posts
        ->withCount(['as first_count' => fn (Builder $builder): Builder => $builder->where('title', 'First')])
        ->withCount('comments')                              // relation count
        ->withSum('comments', 'votes');                      // relation sum

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates['count'])->toBe(2)
        ->and($aggregates['first_count'])->toBe(1)
        ->and($aggregates['comments_count'])->toBe(3)
        ->and($aggregates['comments_sum_votes'])->toBe(10);
});

it('withCount accepts variadic relation names', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withCount('comments', 'tags');

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates['comments_count'])->toBe(3)
        ->and($aggregates['tags_count'])->toBe(0);
});

it('withCount accepts an array of relation names', function (): void {
    $paginator = Post::query()
        ->orderBy('id')
        ->lazyPaginate(10)
        ->withCount(['comments', 'tags']);

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates['comments_count'])->toBe(3)
        ->and($aggregates['tags_count'])->toBe(0);
});

it('aggregate() returns the paginator and hydrates the aggregates property', function (): void {
    $paginator = Post::query()->lazyPaginate(5)->withCount()->aggregate();

    expect($paginator->aggregates)->toBeArray()
        ->and($paginator->aggregates)->toHaveKey('count')
        ->and($paginator->aggregates['count'])->toBe(Post::count());
});

it('aggregate() result is cached — calling toArray() afterwards does not re-run queries', function (): void {
    DB::enableQueryLog();

    $paginator = Post::query()->lazyPaginate(5)->withCount();

    $paginator->aggregate();

    $queryCountAfterAggregate = count(DB::getQueryLog());

    $paginator->toArray();
    $queryCountAfterToArray = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($paginator->aggregates)->toHaveKey('count')
        ->and($queryCountAfterToArray)->toBe($queryCountAfterAggregate); // aggregate already resolved — no extra queries
});

it('aggregate() works on lazySimplePaginate', function (): void {
    $paginator = Post::query()->lazySimplePaginate(5)->withCount()->aggregate();

    expect($paginator->aggregates)->toBeArray()
        ->and($paginator->aggregates)->toHaveKey('count')
        ->and($paginator->aggregates['count'])->toBe(Post::count());
});

it('aggregate() works on lazyCursorPaginate', function (): void {
    $paginator = Post::query()->lazyCursorPaginate(5)->withCount()->aggregate();

    expect($paginator->aggregates)->toBeArray()
        ->and($paginator->aggregates)->toHaveKey('count')
        ->and($paginator->aggregates['count'])->toBe(Post::count());
});

it('base exists resolves correctly when batched with another aggregate', function (): void {
    $aggregates = Post::query()
        ->lazyPaginate(10)
        ->withCount()
        ->withExists()
        ->toArray()['aggregates'];

    expect($aggregates)->toHaveKey('count')
        ->and($aggregates)->toHaveKey('exists')
        ->and($aggregates['exists'])->toBeBool()
        ->and($aggregates['exists'])->toBeTrue();
});

it('base withAvg batched with another aggregate uses CROSS JOIN path and returns correct value', function (): void {
    // Two base aggregates forces the CROSS JOIN path (not the single-instruction scalar path).
    // This exercises resolveBaseResult() with the SUM/COUNT pair for AVG.
    // Votes: 3, 5, 2 → avg = 10/3
    $aggregates = Comment::query()
        ->lazyPaginate(10)
        ->withAvg('votes')
        ->withSum('votes')
        ->toArray()['aggregates'];

    expect($aggregates['avg_votes'])->toEqualWithDelta(10 / 3, 0.001)
        ->and($aggregates['sum_votes'])->toBe(10);
});

it('aggregates reflect the global dataset regardless of which page is requested', function (): void {
    // 2 posts, 1 per page. Aggregate must be identical on both pages.
    $page1 = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1, page: 1)
        ->withCount('comments')
        ->withSum('comments', 'votes')
        ->toArray();

    $page2 = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1, page: 2)
        ->withCount('comments')
        ->withSum('comments', 'votes')
        ->toArray();

    // Both pages see all 3 comments and vote-sum of 10
    expect($page1['aggregates']['comments_count'])->toBe(3)
        ->and($page2['aggregates']['comments_count'])->toBe(3)
        ->and($page1['aggregates']['comments_sum_votes'])->toBe(10)
        ->and($page2['aggregates']['comments_sum_votes'])->toBe(10);
});

it('withCount on a relation does not replace the paginator total', function (): void {
    DB::enableQueryLog();

    $payload = Post::query()
        ->orderBy('id')
        ->lazyPaginate(1)
        ->withCount('comments')
        ->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // relation withCount() must NOT be reused as total — COUNT(*) must still fire
    expect(count($queries))->toBe(3)
        ->and($payload['total'])->toBe(2)
        ->and($payload['aggregates']['comments_count'])->toBe(3);
});
