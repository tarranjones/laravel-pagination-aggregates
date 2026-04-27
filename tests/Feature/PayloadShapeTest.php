<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use TarranJones\LaravelPaginationAggregates\Pagination\CursorPaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\LengthAwarePaginator;
use TarranJones\LaravelPaginationAggregates\Pagination\Paginator;
use TarranJones\LaravelPaginationAggregates\PaginatorFactory;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('shape_posts', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('title');
    });

    Schema::create('shape_comments', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('shape_post_id');
        $blueprint->integer('votes');
    });

    $shapePost = ShapePost::query()->create(['title' => 'Alpha']);
    ShapeComment::query()->create(['shape_post_id' => $shapePost->id, 'votes' => 7]);
    ShapeComment::query()->create(['shape_post_id' => $shapePost->id, 'votes' => 3]);
});

// ─── LengthAwarePaginator payload shape ───────────────────────────────────────

it('lazyPaginate toArray contains all standard LengthAwarePaginator keys plus aggregates', function (): void {
    $payload = ShapePost::query()
        ->orderBy('id')
        ->paginateWithAggregates(15)
        ->withCount('shapeComments')
        ->withSum('shapeComments', 'votes')
        ->toArray();

    expect($payload)->toHaveKeys([
        'current_page',
        'data',
        'first_page_url',
        'from',
        'last_page',
        'last_page_url',
        'links',
        'next_page_url',
        'path',
        'per_page',
        'prev_page_url',
        'to',
        'total',
        'aggregates',
    ]);

    expect($payload['aggregates'])->toHaveKeys(['shape_comments_count', 'shape_comments_sum_votes'])
        ->and($payload['aggregates']['shape_comments_count'])->toBe(2)
        ->and($payload['aggregates']['shape_comments_sum_votes'])->toBe(10)
        ->and($payload['total'])->toBe(1)
        ->and($payload['data'])->toHaveCount(1);
});

it('lazyPaginate toArray does not include aggregates key when no aggregates are configured', function (): void {
    $payload = ShapePost::query()
        ->orderBy('id')
        ->paginateWithAggregates(15)
        ->toArray();

    expect($payload)->not->toHaveKey('aggregates');
});

// ─── Simple paginator payload shape ──────────────────────────────────────────

it('lazySimplePaginate toArray contains all standard Paginator keys plus aggregates', function (): void {
    $payload = ShapePost::query()
        ->orderBy('id')
        ->simplePaginateWithAggregates(15)
        ->withCount('shapeComments')
        ->toArray();

    expect($payload)->toHaveKeys([
        'current_page',
        'data',
        'first_page_url',
        'from',
        'next_page_url',
        'path',
        'per_page',
        'prev_page_url',
        'to',
        'aggregates',
    ]);

    expect($payload)->not->toHaveKey('total')
        ->and($payload['aggregates']['shape_comments_count'])->toBe(2);
});

// ─── Cursor paginator payload shape ──────────────────────────────────────────

it('lazyCursorPaginate toArray contains all standard CursorPaginator keys plus aggregates', function (): void {
    $payload = ShapePost::query()
        ->orderBy('id')
        ->cursorPaginateWithAggregates(15)
        ->withCount('shapeComments')
        ->toArray();

    expect($payload)->toHaveKeys([
        'data',
        'path',
        'per_page',
        'next_cursor',
        'next_page_url',
        'prev_cursor',
        'prev_page_url',
        'aggregates',
    ]);

    expect($payload['aggregates']['shape_comments_count'])->toBe(2);
});

// ─── Two withExists with different constraints ────────────────────────────────

it('two withExists with different constraints produce independent boolean results', function (): void {
    $aggregates = ShapeComment::query()
        ->paginateWithAggregates(10)
        ->withExists(['as has_high' => fn (Builder $builder): Builder => $builder->where('votes', '>', 5)])
        ->withExists(['as has_low' => fn (Builder $builder): Builder => $builder->where('votes', '<', 2)])
        ->toArray()['aggregates'];

    // votes: 7, 3 → has_high (>5): true (7 qualifies); has_low (<2): false (none qualify)
    expect($aggregates['has_high'])->toBeTrue()
        ->and($aggregates['has_low'])->toBeFalse();
});

it('two unconstrained withExists calls with different aliases both resolve to true', function (): void {
    $aggregates = ShapeComment::query()
        ->paginateWithAggregates(10)
        ->withExists(['as first_exists'])
        ->withExists(['as second_exists'])
        ->toArray()['aggregates'];

    expect($aggregates['first_exists'])->toBeTrue()
        ->and($aggregates['second_exists'])->toBeTrue();
});

// ─── PaginatorFactory ─────────────────────────────────────────────────────────

it('PaginatorFactory::paginate returns a LengthAwarePaginator instance', function (): void {
    $lengthAwarePaginator = PaginatorFactory::paginate(ShapePost::query());

    expect($lengthAwarePaginator)->toBeInstanceOf(LengthAwarePaginator::class);
});

it('PaginatorFactory::simplePaginate returns a Paginator instance', function (): void {
    $paginator = PaginatorFactory::simplePaginate(ShapePost::query());

    expect($paginator)->toBeInstanceOf(Paginator::class);
});

it('PaginatorFactory::cursorPaginate returns a CursorPaginator instance', function (): void {
    $cursorPaginator = PaginatorFactory::cursorPaginate(ShapePost::query());

    expect($cursorPaginator)->toBeInstanceOf(CursorPaginator::class);
});

it('PaginatorFactory::paginate passes perPage through to the paginator', function (): void {
    $lengthAwarePaginator = PaginatorFactory::paginate(ShapePost::query(), perPage: 3);

    expect($lengthAwarePaginator->perPage())->toBe(3);
});

// ─── Models ───────────────────────────────────────────────────────────────────

class ShapePost extends Model
{
    protected $table = 'shape_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function shapeComments(): HasMany
    {
        return $this->hasMany(ShapeComment::class);
    }
}

class ShapeComment extends Model
{
    protected $table = 'shape_comments';

    protected $guarded = [];

    public $timestamps = false;
}
