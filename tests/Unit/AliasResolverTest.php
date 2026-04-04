<?php

declare(strict_types=1);

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use TarranJones\LaravelPaginationAggregates\AliasResolver;
use Tests\TestCase;

uses(TestCase::class);

// ─── explicitAlias ────────────────────────────────────────────────────────────

it('explicitAlias returns alias from "relation as alias" form', function (): void {
    expect(AliasResolver::explicitAlias('comments as top_vote'))->toBe('top_vote');
});

it('explicitAlias returns null when no alias is present', function (): void {
    expect(AliasResolver::explicitAlias('comments'))->toBeNull();
});

it('explicitAlias returns null for the bare "as alias" base form', function (): void {
    // "as my_alias" has only 2 segments — explicitAlias requires exactly 3
    expect(AliasResolver::explicitAlias('as my_alias'))->toBeNull();
});

it('explicitAlias is case-insensitive on the AS keyword', function (): void {
    expect(AliasResolver::explicitAlias('comments AS top_vote'))->toBe('top_vote');
});

it('explicitAlias returns null for a four-segment string', function (): void {
    // "comments votes as top_vote" has 4 segments — not a valid explicit alias form
    expect(AliasResolver::explicitAlias('comments votes as top_vote'))->toBeNull();
});

// ─── baseAlias ────────────────────────────────────────────────────────────────

it('baseAlias returns alias from "as alias" form', function (): void {
    expect(AliasResolver::baseAlias('as my_alias'))->toBe('my_alias');
});

it('baseAlias returns null when first word is not "as"', function (): void {
    expect(AliasResolver::baseAlias('comments as top_vote'))->toBeNull();
});

it('baseAlias returns null for a plain column name', function (): void {
    expect(AliasResolver::baseAlias('votes'))->toBeNull();
});

it('baseAlias is case-insensitive on the AS keyword', function (): void {
    expect(AliasResolver::baseAlias('AS my_alias'))->toBe('my_alias');
});

it('baseAlias trims surrounding whitespace before parsing', function (): void {
    expect(AliasResolver::baseAlias('  as trimmed  '))->toBe('trimmed');
});

// ─── stripAlias ───────────────────────────────────────────────────────────────

it('stripAlias removes " as alias" suffix', function (): void {
    expect(AliasResolver::stripAlias('comments as top_vote'))->toBe('comments');
});

it('stripAlias leaves a string without an alias unchanged', function (): void {
    expect(AliasResolver::stripAlias('comments'))->toBe('comments');
});

it('stripAlias is case-insensitive on the AS keyword', function (): void {
    expect(AliasResolver::stripAlias('comments AS top_vote'))->toBe('comments');
});

it('stripAlias handles multiple spaces before as', function (): void {
    expect(AliasResolver::stripAlias('comments  as top_vote'))->toBe('comments');
});

// ─── forRelation ─────────────────────────────────────────────────────────────

it('forRelation generates a snake_case alias from relation, function, and column', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());

    expect($resolver->forRelation('comments', 'votes', 'sum'))->toBe('comments_sum_votes');
});

it('forRelation uses explicit alias when present in relation string', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());

    // "comments as top_vote" → explicitAlias returns "top_vote"
    expect($resolver->forRelation('comments as top_vote', 'votes', 'max'))->toBe('top_vote');
});

it('forRelation handles Expression column by resolving its value', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());
    $expression = new Expression('LENGTH(content)');

    expect($resolver->forRelation('comments', $expression, 'max'))->toBe('comments_max_length_content');
});

it('forRelation converts uppercase column names to lowercase', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());

    expect($resolver->forRelation('comments', 'VOTES', 'max'))->toBe('comments_max_votes');
});

// ─── forColumn ────────────────────────────────────────────────────────────────

it('forColumn generates a snake_case alias from function and column', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());

    expect($resolver->forColumn('votes', 'sum'))->toBe('sum_votes');
});

it('forColumn uses explicit alias when present', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());

    expect($resolver->forColumn('id as max_post_id', 'max'))->toBe('max_post_id');
});

it('forColumn handles Expression column by resolving its value', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());
    $expression = new Expression('LENGTH(content)');

    expect($resolver->forColumn($expression, 'max'))->toBe('max_length_content');
});

it('forColumn converts uppercase column names to lowercase', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());

    expect($resolver->forColumn('VOTES', 'avg'))->toBe('avg_votes');
});

it('forColumn sanitizes dot-separated column references to underscores', function (): void {
    $resolver = new AliasResolver(DB::connection()->getQueryGrammar());
    // e.g. a raw expression referencing a qualified column
    $expression = new Expression('posts.votes');

    expect($resolver->forColumn($expression, 'sum'))->toBe('sum_posts_votes');
});
