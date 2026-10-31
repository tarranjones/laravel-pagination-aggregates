<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\SdComment;
use Tests\Fixtures\Models\SdPost;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('sd_posts', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('title');
        $blueprint->softDeletes();
    });

    Schema::create('sd_comments', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('sd_post_id');
        $blueprint->integer('votes');
        $blueprint->softDeletes();
    });

    $sdPost = SdPost::query()->create(['title' => 'First']);
    $postTwo = SdPost::query()->create(['title' => 'Second']);

    SdComment::query()->create(['sd_post_id' => $sdPost->id, 'votes' => 3]);
    SdComment::query()->create(['sd_post_id' => $sdPost->id, 'votes' => 5]);
    SdComment::query()->create(['sd_post_id' => $postTwo->id, 'votes' => 2]);
});

it('relation aggregate excludes soft-deleted child rows', function (): void {
    // Soft-delete the high-vote comment; only votes 3 and 2 remain
    SdComment::query()->where('votes', 5)->first()->delete();

    $paginator = SdPost::query()
        ->paginateWithAggregates(10)
        ->withAggSum('sdComments', 'votes');

    $aggregates = $paginator->toArray()['aggregates'];

    // 5 is deleted — sum should be 3 + 2 = 5, not 10
    expect($aggregates['sd_comments_sum_votes'])->toBe(5);
});

it('relation count aggregate excludes soft-deleted child rows', function (): void {
    SdComment::query()->where('votes', 5)->first()->delete();

    $paginator = SdPost::query()
        ->paginateWithAggregates(10)
        ->withAggCount('sdComments');

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates['sd_comments_count'])->toBe(2);
});

it('base count aggregate excludes soft-deleted parent rows', function (): void {
    SdPost::query()->where('title', 'Second')->first()->delete();

    $paginator = SdPost::query()
        ->paginateWithAggregates(10)
        ->withAggCount();

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates['count'])->toBe(1);
});

it('soft-deleted parent rows are excluded from pagination results', function (): void {
    SdPost::query()->where('title', 'Second')->first()->delete();

    $paginator = SdPost::query()
        ->paginateWithAggregates(10)
        ->withAggCount();

    $payload = $paginator->toArray();

    expect($payload['total'])->toBe(1)
        ->and($payload['data'])->toHaveCount(1);
});

// ─── Models ───────────────────────────────────────────────────────────────────
