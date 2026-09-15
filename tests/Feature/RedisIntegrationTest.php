<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Webrek\MongoPermission\Tests\AuditTestCase;
use Webrek\MongoPermission\PermissionRegistrar;

class RedisIntegrationTest extends AuditTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Cache::getStore() instanceof RedisStore) {
            $this->markTestSkipped('Set PERMISSION_TEST_CACHE=redis');
        }
    }

    private function worker(string $action): Process
    {
        return new Process([PHP_BINARY, __DIR__.'/../Support/redis-worker.php', $action], null, [
            'PERMISSION_TEST_CACHE' => 'redis',
            'PERMISSION_MUTATION_ISOLATION' => false,
            'MONGO_DB_HOST' => (string) config('database.connections.mongodb.host'),
            'MONGO_DB_PORT' => (string) config('database.connections.mongodb.port'),
            'MONGO_DB_DATABASE' => (string) config('database.connections.mongodb.database'),
            'REDIS_HOST' => (string) config('database.redis.default.host'),
            'REDIS_PORT' => (string) config('database.redis.default.port'),
        ]);
    }

    private function mutate(string $action): void
    {
        $process = $this->worker($action);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }

    public function test_long_lived_reader_sees_role_changes_from_other_processes(): void
    {
        $reader = new PermissionRegistrar;
        $user = $this->user('editor');
        $this->assertNotContains('articles.publish', $reader->getUserPermissionSlugs($user));
        $this->mutate('grant-role-permission');
        $this->assertContains('articles.publish', $reader->getUserPermissionSlugs($user));
        $this->mutate('revoke-role-permission');
        $this->assertNotContains('articles.publish', $reader->getUserPermissionSlugs($user));
    }

    public function test_long_lived_reader_sees_direct_changes_from_other_processes(): void
    {
        $reader = new PermissionRegistrar;
        $user = $this->user('lector');
        $this->assertNotContains('articles.publish', $reader->getUserPermissionSlugs($user));
        $this->mutate('grant-direct');
        $this->assertContains('articles.publish', $reader->getUserPermissionSlugs($user));
        $this->mutate('revoke-direct');
        $this->assertNotContains('articles.publish', $reader->getUserPermissionSlugs($user));
    }

    public function test_four_processes_preserve_all_160_generation_increments(): void
    {
        $reader = new PermissionRegistrar;
        $before = $reader->cacheVersion();
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = $this->worker('increment');
                $process->start();
                $workers[] = $process;
            }
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }
            $this->assertSame($before + 160, $reader->cacheVersion());
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
