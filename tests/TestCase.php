<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') === 'mysql' && config('database.connections.mysql.database') !== 'growthdesk_testing') {
            throw new \RuntimeException('MySQL tests require the isolated growthdesk_testing database.');
        }

        return $app;
    }
}
