<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    foreach (range(1, 12) as $i) {
        $post = Post::query()->create(['title' => 'Post '.$i]);
        Comment::query()->create(['post_id' => $post->id, 'votes' => $i]);
    }
});

// ─── Deferred state is populated on first read ───────────────────────────────

it('length-aware paginator exposes items and totals before serialization', function (): void {
    $paginator = Post::query()->orderBy('id')->paginateWithAggregates(5)->withCount('comments');

    expect($paginator->count())->toBe(5)
        ->and($paginator->total())->toBe(12)
        ->and($paginator->lastPage())->toBe(3)
        ->and($paginator->items())->toHaveCount(5)
        ->and(iterator_to_array($paginator))->toHaveCount(5)
        ->and($paginator->toArray()['aggregates']['comments_count'])->toBe(12);
});

it('simple paginator exposes items and hasMorePages before serialization', function (): void {
    $paginator = Post::query()->orderBy('id')->simplePaginateWithAggregates(5)->withCount('comments');

    expect($paginator->count())->toBe(5)
        ->and($paginator->hasMorePages())->toBeTrue();
});

it('cursor paginator exposes items and next cursor before serialization', function (): void {
    $paginator = Post::query()->orderBy('id')->cursorPaginateWithAggregates(5)->withCount('comments');

    expect($paginator->count())->toBe(5)
        ->and($paginator->hasMorePages())->toBeTrue()
        ->and($paginator->nextCursor())->not->toBeNull();
});

it('through() transforms the paginated items', function (): void {
    $paginator = Post::query()->orderBy('id')->paginateWithAggregates(2)->withCount('comments');

    $paginator->through(fn (Post $post): array => ['title' => $post->title]);

    expect($paginator->toArray()['data'])->toBe([['title' => 'Post 1'], ['title' => 'Post 2']]);
});

it('setCollection() before initialization is preserved', function (): void {
    $paginator = Post::query()->orderBy('id')->paginateWithAggregates(5);

    $paginator->setCollection(collect(['replaced']));

    expect($paginator->toArray()['data'])->toBe(['replaced'])
        ->and($paginator->total())->toBe(12);
});

it('JsonResource::collection receives the page data and aggregates', function (): void {
    $paginator = Post::query()->orderBy('id')->paginateWithAggregates(5)->withCount('comments');

    $response = JsonResource::collection($paginator)->response()->getData(true);

    expect($response['data'])->toHaveCount(5)
        ->and($response['meta']['total'])->toBe(12)
        ->and($response['meta']['aggregates']['comments_count'])->toBe(12);
});

it('the pagination query runs only once across multiple reads', function (): void {
    DB::enableQueryLog();

    $paginator = Post::query()->paginateWithAggregates(5)->withCount('comments');
    $paginator->count();
    $paginator->total();
    $paginator->toArray();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(3);
});

it('protected paginator properties remain inaccessible from outside', function (): void {
    $paginator = Post::query()->paginateWithAggregates(5);

    $paginator->toArray();

    expect(fn () => $paginator->items)->toThrow(Error::class);
});

// ─── Aggregate query does not load the full result set into PHP ─────────────

it('relation aggregates hydrate only the current page of models', function (): void {
    $hydrated = 0;
    Event::listen('eloquent.retrieved: '.Post::class, function () use (&$hydrated): void {
        $hydrated++;
    });

    $aggregates = Post::query()
        ->paginateWithAggregates(5)
        ->withCount('comments')
        ->withSum('comments', 'votes')
        ->withMax('id')
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
        ->select('post_id')
        ->groupBy('post_id')
        ->paginateWithAggregates(5)
        ->withMax('votes');

    expect($paginator->total())->toBe(12)
        ->and($paginator->count())->toBe(5);
});
