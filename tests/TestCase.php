<?php

namespace Webrek\MongoPermission\Tests;

use MongoDB\Laravel\MongoDBServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Webrek\MongoPermission\MongoPermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->flushMongo();
        $this->app->make(\Webrek\MongoPermission\PermissionRegistrar::class)->forgetTeamId();
    }

    protected function getPackageProviders($app): array
    {
        return [
            MongoDBServiceProvider::class,
            MongoPermissionServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'mongodb');
        $app['config']->set('database.connections.mongodb', [
            'driver' => 'mongodb',
            'host' => env('MONGO_DB_HOST', '127.0.0.1'),
            'port' => (int) env('MONGO_DB_PORT', 27017),
            'database' => env('MONGO_DB_DATABASE', 'permission_test'),
            'options' => [],
        ]);

        $app['config']->set('auth.providers.users.model', \Webrek\MongoPermission\Tests\Models\TestUser::class);
    }

    protected function flushMongo(): void
    {
        $db = $this->app['db']->connection('mongodb')->getMongoDB();
        $name = $db->getDatabaseName();
        if (! str_ends_with($name, '_test')) {
            throw new \RuntimeException("flushMongo refuses to drop non-test database '{$name}'.");
        }
        foreach ($db->listCollectionNames() as $coll) {
            $db->dropCollection($coll);
        }
    }
}
