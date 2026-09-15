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
        \Illuminate\Support\Facades\Cache::flush();
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

        if (env('PERMISSION_TEST_CACHE') === 'redis') {
            // TestCase flushes this DB: never point it at application Redis.
            $app['config']->set('cache.default', 'redis');
            $app['config']->set('cache.prefix', 'permission-package-test:');
            $app['config']->set('database.redis', [
                'client' => 'phpredis',
                'options' => ['prefix' => 'permission-package-test:'],
                'default' => [
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => (int) env('REDIS_PORT', 6379),
                    'database' => 14,
                ],
                'cache' => [
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => (int) env('REDIS_PORT', 6379),
                    'database' => 15,
                ],
            ]);
        }

        $app['config']->set('auth.providers.users.model', \Webrek\MongoPermission\Tests\Models\TestUser::class);
    }

    protected function advanceCacheClock(): void
    {
        if (\Illuminate\Support\Facades\Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            // Redis TTL follows its server clock, not Carbon's test clock.
            usleep(2100000);
        } else {
            $this->travel(2)->seconds();
        }
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
