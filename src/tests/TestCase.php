<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.mysql.database');
        $connection = config('database.default');

        if (
            ! app()->environment('testing') ||
            $connection !== 'mysql' ||
            $database !== 'laravel_testing'
        ) {
            throw new RuntimeException(
                'Tests must use the laravel_testing MySQL database.'
            );
        }
    }
}
