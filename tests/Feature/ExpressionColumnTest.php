<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\ExprComment;
use Tests\Fixtures\Models\ExprPost;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('expr_posts', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('title');
    });

    Schema::create('expr_comments', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('expr_post_id');
        $blueprint->string('content');
        $blueprint->integer('votes');
    });

    $exprPost = ExprPost::query()->create(['title' => 'First']);
    ExprComment::query()->create(['expr_post_id' => $exprPost->id, 'content' => 'hello', 'votes' => 3]);
    ExprComment::query()->create(['expr_post_id' => $exprPost->id, 'content' => 'world', 'votes' => 5]);
});

it('aggregateMax accepts a DB::raw() Expression as column on a relation aggregate', function (): void {
    $paginator = ExprPost::query()
        ->paginateWithAggregates(10)
        ->aggregateMax('exprComments', DB::raw('votes'));

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates)->toHaveKey('expr_comments_max_votes')
        ->and($aggregates['expr_comments_max_votes'])->toBe(5);
});

it('aggregateMin accepts a DB::raw() Expression as column on a relation aggregate', function (): void {
    $paginator = ExprPost::query()
        ->paginateWithAggregates(10)
        ->aggregateMin('exprComments', DB::raw('votes'));

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates)->toHaveKey('expr_comments_min_votes')
        ->and($aggregates['expr_comments_min_votes'])->toBe(3);
});

it('aggregateSum accepts a DB::raw() Expression as column on a relation aggregate', function (): void {
    $paginator = ExprPost::query()
        ->paginateWithAggregates(10)
        ->aggregateSum('exprComments', DB::raw('votes'));

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates)->toHaveKey('expr_comments_sum_votes')
        ->and($aggregates['expr_comments_sum_votes'])->toBe(8);
});

it('aggregateAvg accepts a DB::raw() Expression as column on a relation aggregate', function (): void {
    $paginator = ExprPost::query()
        ->paginateWithAggregates(10)
        ->aggregateAvg('exprComments', DB::raw('votes'));

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates)->toHaveKey('expr_comments_avg_votes')
        ->and($aggregates['expr_comments_avg_votes'])->toEqualWithDelta(4.0, 0.001);
});

it('aggregateMax accepts a DB::raw() Expression as column on a base aggregate', function (): void {
    $paginator = ExprComment::query()
        ->paginateWithAggregates(10)
        ->aggregateMax(DB::raw('votes'));

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates)->toHaveKey('max_votes')
        ->and($aggregates['max_votes'])->toBe(5);
});

it('aggregateSum accepts a DB::raw() Expression as column on a base aggregate', function (): void {
    $paginator = ExprComment::query()
        ->paginateWithAggregates(10)
        ->aggregateSum(DB::raw('votes'));

    $aggregates = $paginator->toArray()['aggregates'];

    expect($aggregates)->toHaveKey('sum_votes')
        ->and($aggregates['sum_votes'])->toBe(8);
});

// ─── Models ───────────────────────────────────────────────────────────────────
