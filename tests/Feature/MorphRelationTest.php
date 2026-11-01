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

    Schema::create('reactions', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->morphs('reactable');
        $blueprint->integer('score');
    });

    $post = Post::query()->create(['title' => 'First']);
    Post::query()->create(['title' => 'Second']);

    // Comment 1 shares id 1 with post 1: its reactions must not be counted as the post's.
    $comment = Comment::query()->create(['post_id' => $post->id, 'votes' => 1]);

    $post->reactions()->create(['score' => 5]);
    $post->reactions()->create(['score' => 3]);
    $comment->reactions()->create(['score' => 100]);
    $comment->reactions()->create(['score' => 100]);
});

it('counts only reactions of the paginated model type', function (): void {
    $aggregates = Post::query()
        ->withAggCount('reactions')
        ->withAggSum('reactions', 'score')
        ->withAggMax('reactions', 'score')
        ->withAggAvg('reactions', 'score')
        ->paginateWithAggregates(10)
        ->aggregates;

    expect($aggregates)->toBe([
        'reactions_count' => 2,
        'reactions_sum_score' => 8,
        'reactions_max_score' => 5,
        'reactions_avg_score' => 4.0,
    ]);
});

it('applies constraints to morph relation aggregates', function (): void {
    $aggregates = Post::query()
        ->withAggCount(['reactions as high_reactions' => fn (Builder $builder): Builder => $builder->where('score', '>', 4)])
        ->withAggExists('reactions')
        ->paginateWithAggregates(10)
        ->aggregates;

    expect($aggregates)->toBe(['high_reactions' => 1, 'reactions_exists' => true]);
});

it('aggregates morph relations from the other model type too', function (): void {
    $aggregates = Comment::query()->withAggSum('reactions', 'score')->paginateWithAggregates(10)->aggregates;

    expect($aggregates)->toBe(['reactions_sum_score' => 200]);
});
