<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('ho_users', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('name');
    });

    Schema::create('ho_profiles', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('ho_user_id');
        $blueprint->integer('score');
    });

    $hoUser = HoUser::query()->create(['name' => 'Alice']);
    $bob = HoUser::query()->create(['name' => 'Bob']);
    $carol = HoUser::query()->create(['name' => 'Carol']);

    // Alice: score 80, Bob: score 50, Carol: no profile
    HoProfile::query()->create(['ho_user_id' => $hoUser->id, 'score' => 80]);
    HoProfile::query()->create(['ho_user_id' => $bob->id, 'score' => 50]);
});

it('withCount on HasOne returns total number of related rows across all parents', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withCount('hoProfile')
        ->toArray()['aggregates'];

    // 2 users have a profile, 1 does not — global count = 2
    expect($aggregates['ho_profile_count'])->toBe(2);
});

it('withMax on HasOne returns the max value across all related rows', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withMax('hoProfile', 'score')
        ->toArray()['aggregates'];

    expect($aggregates['ho_profile_max_score'])->toBe(80);
});

it('withMin on HasOne returns the min value across all related rows', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withMin('hoProfile', 'score')
        ->toArray()['aggregates'];

    expect($aggregates['ho_profile_min_score'])->toBe(50);
});

it('withSum on HasOne returns the sum across all related rows', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withSum('hoProfile', 'score')
        ->toArray()['aggregates'];

    expect($aggregates['ho_profile_sum_score'])->toBe(130);
});

it('withAvg on HasOne returns the average across all related rows', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withAvg('hoProfile', 'score')
        ->toArray()['aggregates'];

    // rows: 80, 50 (Carol has no profile, contributes null/0 via LEFT JOIN)
    // SUM=130, COUNT=2 → 65.0
    expect($aggregates['ho_profile_avg_score'])->toEqualWithDelta(65.0, 0.001);
});

it('withExists on HasOne returns true when at least one parent has a related row', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withExists('hoProfile')
        ->toArray()['aggregates'];

    expect($aggregates['ho_profile_exists'])->toBeTrue();
});

it('withExists on HasOne returns false when no related rows exist', function (): void {
    HoProfile::query()->delete();

    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withExists('hoProfile')
        ->toArray()['aggregates'];

    expect($aggregates['ho_profile_exists'])->toBeFalse();
});

it('withCount on HasOne with a closure constraint counts only matching rows', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withCount(['hoProfile as high_score_count' => fn (Builder $builder): Builder => $builder->where('score', '>', 60)])
        ->toArray()['aggregates'];

    // Only Alice (score 80) qualifies
    expect($aggregates['high_score_count'])->toBe(1);
});

it('withMax and withMin on HasOne are batched into a single LEFT JOIN', function (): void {
    $aggregates = HoUser::query()
        ->lazyPaginate(10)
        ->withMax('hoProfile', 'score')
        ->withMin('hoProfile', 'score')
        ->toArray()['aggregates'];

    expect($aggregates['ho_profile_max_score'])->toBe(80)
        ->and($aggregates['ho_profile_min_score'])->toBe(50);
});

// ─── Models ───────────────────────────────────────────────────────────────────

class HoUser extends Model
{
    protected $table = 'ho_users';

    protected $guarded = [];

    public $timestamps = false;

    public function hoProfile(): HasOne
    {
        return $this->hasOne(HoProfile::class);
    }
}

class HoProfile extends Model
{
    protected $table = 'ho_profiles';

    protected $guarded = [];

    public $timestamps = false;
}
