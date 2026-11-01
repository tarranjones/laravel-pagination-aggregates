<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\CursorPaginator as LaravelCursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Pagination\Paginator as LaravelPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use TarranJones\LaravelPaginationAggregates\Pagination\LengthAwarePaginator;
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

    foreach (range(1, 12) as $i) {
        $post = Post::query()->create(['title' => 'Post '.$i]);
        Comment::query()->create(['post_id' => $post->id, 'votes' => $i]);
    }
});

// ─── The paginator behaves exactly like Laravel's ────────────────────────────

it('length-aware paginator exposes items and totals', function (): void {
    $paginator = Post::query()->orderBy('id')->withAggCount('comments')->paginateWithAggregates(5);

    expect($paginator)->toBeInstanceOf(LaravelLengthAwarePaginator::class)
        ->and($paginator->count())->toBe(5)
        ->and($paginator->total())->toBe(12)
        ->and($paginator->lastPage())->toBe(3)
        ->and($paginator->items())->toHaveCount(5)
        ->and(iterator_to_array($paginator))->toHaveCount(5)
        ->and($paginator->aggregates['comments_count'])->toBe(12);
});

it('simple paginator exposes items and hasMorePages', function (): void {
    $paginator = Post::query()->orderBy('id')->withAggCount('comments')->simplePaginateWithAggregates(5);

    expect($paginator)->toBeInstanceOf(LaravelPaginator::class)
        ->and($paginator->count())->toBe(5)
        ->and($paginator->hasMorePages())->toBeTrue();
});

it('cursor paginator keeps hasMore and item order when navigating back', function (): void {
    $first = Post::query()->orderBy('id')->withAggCount('comments')->cursorPaginateWithAggregates(5);
    $second = Post::query()->orderBy('id')->cursorPaginateWithAggregates(5, cursor: $first->nextCursor());
    $back = Post::query()->orderBy('id')->withAggCount('comments')->cursorPaginateWithAggregates(5, cursor: $second->previousCursor());

    expect($first)->toBeInstanceOf(LaravelCursorPaginator::class)
        ->and($first->hasMorePages())->toBeTrue()
        ->and($second->pluck('id')->all())->toBe([6, 7, 8, 9, 10])
        ->and($back->pluck('id')->all())->toBe([1, 2, 3, 4, 5])
        ->and($back->aggregates['comments_count'])->toBe(12);
});

it('through() transforms the paginated items', function (): void {
    $paginator = Post::query()->orderBy('id')->withAggCount('comments')->paginateWithAggregates(2);

    $paginator->through(fn (Post $post): array => ['title' => $post->title]);

    expect($paginator->toArray()['data'])->toBe([['title' => 'Post 1'], ['title' => 'Post 2']]);
});

it('JsonResource::collection receives the page data and aggregates', function (): void {
    $paginator = Post::query()->orderBy('id')->withAggCount('comments')->paginateWithAggregates(5);

    $response = JsonResource::collection($paginator)->response()->getData(true);

    expect($response['data'])->toHaveCount(5)
        ->and($response['meta']['total'])->toBe(12)
        ->and($response['meta']['aggregates']['comments_count'])->toBe(12);
});

it('reading the paginator runs no further queries', function (): void {
    $paginator = Post::query()->withAggCount('comments')->paginateWithAggregates(5);

    DB::enableQueryLog();
    $paginator->count();
    $paginator->total();
    $paginator->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('withAgg*() methods are not available on the paginator', function (): void {
    $paginator = Post::query()->paginateWithAggregates(5);

    expect(fn () => $paginator->withAggCount('comments'))->toThrow(BadMethodCallException::class);
});

// ─── Aggregates declared on the query ────────────────────────────────────────

it('applies constraints added to the query after withAgg*()', function (): void {
    $aggregates = Post::query()
        ->withAggCount()
        ->withAggCount('comments')
        ->where('id', '<=', 3)
        ->paginateWithAggregates(5)
        ->aggregates;

    expect($aggregates)->toBe(['count' => 3, 'comments_count' => 3]);
});

it('gives a cloned query its own copy of the aggregates', function (): void {
    $original = Post::query()->withAggCount();
    $clone = (clone $original)->withAggCount('comments');
    $original->withAggMax('id');

    expect($original->paginateWithAggregates(5)->aggregates)->toBe(['count' => 12, 'max_id' => 12])
        ->and($clone->paginateWithAggregates(5)->aggregates)->toBe(['count' => 12, 'comments_count' => 12]);
});

it('keeps aggregates through clones of clones', function (): void {
    $base = Post::query()->withAggCount();
    $child = (clone $base)->withAggMax('id');
    $grandchild = clone $child;

    expect($grandchild->paginateWithAggregates(5)->aggregates)->toBe(['count' => 12, 'max_id' => 12])
        ->and($base->paginateWithAggregates(5)->aggregates)->toBe(['count' => 12]);
});

it("ignores declared aggregates for Laravel's own paginate()", function (): void {
    $paginator = Post::query()->withAggCount('comments')->paginate(5);

    expect($paginator)->not->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->toArray())->not->toHaveKey('aggregates');
});

it('returns an empty aggregates array when none are declared', function (): void {
    $paginator = Post::query()->paginateWithAggregates(5);

    expect($paginator->aggregates)->toBe([])
        ->and($paginator->toArray())->not->toHaveKey('aggregates');
});

// ─── Aggregate query does not load the full result set into PHP ─────────────

it('relation aggregates hydrate only the current page of models', function (): void {
    $hydrated = 0;
    Event::listen('eloquent.retrieved: '.Post::class, function () use (&$hydrated): void {
        $hydrated++;
    });

    $aggregates = Post::query()
        ->withAggCount('comments')
        ->withAggSum('comments', 'votes')
        ->withAggMax('id')
        ->paginateWithAggregates(5)
        ->toArray()['aggregates'];

    expect($hydrated)->toBe(5)
        ->and($aggregates)->toBe([
            'comments_count' => 12,
            'comments_sum_votes' => 78,
            'max_id' => 12,
        ]);
});

// ─── Grouped base queries ────────────────────────────────────────────────────

it('grouped base queries keep the correct paginator total', function (): void {
    Comment::query()->create(['post_id' => 1, 'votes' => 100]);

    $paginator = Comment::query()
        ->selectRaw('post_id, MAX(votes) AS top_votes')
        ->groupBy('post_id')
        ->withAggMax('top_votes')
        ->paginateWithAggregates(5);

    expect($paginator->total())->toBe(12)
        ->and($paginator->count())->toBe(5)
        ->and((int) $paginator->aggregates['max_top_votes'])->toBe(100);
});
