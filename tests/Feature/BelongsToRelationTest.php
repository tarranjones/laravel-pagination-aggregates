<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $first = Post::query()->create(['title' => 'First']);
    $second = Post::query()->create(['title' => 'Second']);

    // Comments reference post ids 1, 1, 2
    Comment::query()->create(['post_id' => $first->id, 'votes' => 3]);
    Comment::query()->create(['post_id' => $first->id, 'votes' => 5]);
    Comment::query()->create(['post_id' => $second->id, 'votes' => 2]);
});

it('withAggAvg on a BelongsTo relation averages across every base row', function (): void {
    $aggregates = Comment::query()
        ->withAggAvg('post', 'id')
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    // (1 + 1 + 2) / 3
    expect($aggregates['post_avg_id'])->toEqualWithDelta(4 / 3, 0.001);
});

it('withAggAvg on a BelongsTo relation honours alias and constraint', function (): void {
    $aggregates = Comment::query()
        ->withAggAvg(['post as second_post_avg' => fn (Builder $builder): Builder => $builder->where('title', 'Second')], 'id')
        ->withAggCount('post')
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    expect($aggregates['second_post_avg'])->toEqualWithDelta(2.0, 0.001)
        ->and($aggregates['post_count'])->toBe(3);
});

it('BelongsTo max, min and exists roll up across every base row', function (): void {
    $aggregates = Comment::query()
        ->withAggMax('post', 'id')
        ->withAggMin('post', 'id')
        ->withAggExists('post')
        ->paginateWithAggregates(1)
        ->toArray()['aggregates'];

    expect($aggregates['post_max_id'])->toBe(2)
        ->and($aggregates['post_min_id'])->toBe(1)
        ->and($aggregates['post_exists'])->toBeTrue();
});
