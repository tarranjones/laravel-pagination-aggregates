<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use TarranJones\LaravelPaginationAggregates\PaginationAggregatesServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaginationAggregatesServiceProvider::class];
    }
}
