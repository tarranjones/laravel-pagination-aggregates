<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Order;
use Tests\Fixtures\Models\OrderLine;

uses(RefreshDatabase::class);

/*
 * Drivers disagree on result types (MySQL/PostgreSQL return SUM and AVG as numeric strings,
 * SQLite returns native numbers). These tests pin the normalised types on every driver.
 */

beforeEach(function (): void {
    Schema::create('orders', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('status');
        $blueprint->integer('quantity');
        $blueprint->decimal('total', 8, 2);
    });

    Schema::create('order_lines', function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->foreignId('order_id');
        $blueprint->integer('units');
        $blueprint->decimal('price', 8, 2);
    });

    $first = Order::query()->create(['status' => 'paid', 'quantity' => 2, 'total' => '10.25']);
    $second = Order::query()->create(['status' => 'paid', 'quantity' => 3, 'total' => '20.50']);
    Order::query()->create(['status' => 'pending', 'quantity' => 4, 'total' => '5.00']);

    OrderLine::query()->create(['order_id' => $first->id, 'units' => 1, 'price' => '1.50']);
    OrderLine::query()->create(['order_id' => $first->id, 'units' => 2, 'price' => '2.25']);
    OrderLine::query()->create(['order_id' => $second->id, 'units' => 3, 'price' => '4.00']);
});

it('normalises single scalar base aggregates', function (string $method, string $column, mixed $expected): void {
    $aggregates = Order::query()->{$method}($column)->paginateWithAggregates(1)->toArray()['aggregates'];

    expect(array_values($aggregates)[0])->toBe($expected);
})->with([
    'integer sum' => ['withAggSum', 'quantity', 9],
    'decimal sum' => ['withAggSum', 'total', 35.75],
    'integer avg' => ['withAggAvg', 'quantity', 3.0],
]);

it('normalises batched base aggregates', function (): void {
    $aggregates = Order::query()
        ->withAggCount()
        ->withAggSum('quantity')
        ->withAggSum('total')
        ->withAggAvg('total')
        ->withAggExists(['as has_pending' => fn (Builder $builder): Builder => $builder->where('status', 'pending')])
        ->paginateWithAggregates(1)
        ->toArray()['aggregates'];

    expect($aggregates['count'])->toBe(3)
        ->and($aggregates['sum_quantity'])->toBe(9)
        ->and($aggregates['sum_total'])->toBe(35.75)
        ->and($aggregates['avg_total'])->toEqualWithDelta(35.75 / 3, 0.0001)
        ->and($aggregates['has_pending'])->toBeTrue();
});

it('normalises relation aggregates', function (): void {
    $aggregates = Order::query()
        ->withAggCount('lines')
        ->withAggSum('lines', 'units')
        ->withAggSum('lines', 'price')
        ->withAggAvg('lines', 'units')
        ->withAggExists('lines')
        ->paginateWithAggregates(1)
        ->toArray()['aggregates'];

    expect($aggregates['lines_count'])->toBe(3)
        ->and($aggregates['lines_sum_units'])->toBe(6)
        ->and($aggregates['lines_sum_price'])->toBe(7.75)
        ->and($aggregates['lines_avg_units'])->toBe(2.0)
        ->and($aggregates['lines_exists'])->toBeTrue();
});

it('normalises aggregates computed from the loaded page when the total is pre-known', function (): void {
    $aggregates = Order::query()
        ->withAggSum('total')
        ->withAggAvg('quantity')
        ->paginateWithAggregates(10, total: 3)
        ->toArray()['aggregates'];

    expect($aggregates['sum_total'])->toBe(35.75)
        ->and($aggregates['avg_quantity'])->toBe(3.0);
});

it('returns zero sums and null averages for an empty result set', function (): void {
    $aggregates = Order::query()
        ->where('status', 'refunded')
        ->withAggSum('total')
        ->withAggAvg('total')
        ->withAggSum('lines', 'price')
        ->paginateWithAggregates(10)
        ->toArray()['aggregates'];

    expect($aggregates['sum_total'])->toBe(0)
        ->and($aggregates['avg_total'])->toBeNull()
        ->and($aggregates['lines_sum_price'])->toBe(0);
});
