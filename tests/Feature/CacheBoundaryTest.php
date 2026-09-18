<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\TestCase;

class CacheBoundaryTest extends TestCase
{
    public function test_forgetting_explicit_team_reenables_resolver_including_numeric_and_null_values(): void
    {
        $team = 12;
        config(['permission.team_resolver' => function () use (&$team) {
            return $team;
        }]);
        $reader = app(PermissionRegistrar::class);
        $this->assertSame('12', $reader->getTeamId());
        $this->assertSame($reader, $reader->setTeamId(null));
        $this->assertNull($reader->getTeamId());
        $this->assertSame($reader, $reader->forgetTeamId());
        $this->assertSame('12', $reader->getTeamId());
        $team = null;
        $this->assertNull($reader->getTeamId());
    }

    public function test_hierarchy_mutations_reject_a_store_without_locks_before_running_callback(): void
    {
        $store = Mockery::mock(Store::class);
        Cache::shouldReceive('store')->andReturn(new Repository($store));
        $reader = new PermissionRegistrar;
        $called = false;
        try {
            $reader->withLock('hierarchy', function () use (&$called) {
                $called = true;
            });
            $this->fail('A lockless store must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('atomic locks', $e->getMessage());
            $this->assertFalse($called);
        }
    }

    public function test_generation_rejects_a_non_laravel_repository(): void
    {
        $repository = Mockery::mock(RepositoryContract::class);
        $repository->shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('store')->andReturn($repository);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Laravel cache repository');
        (new PermissionRegistrar)->cacheVersion();
    }

    public function test_generation_rejects_a_laravel_repository_without_lock_support(): void
    {
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('store')->andReturn(new Repository($store));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('atomic locks');
        (new PermissionRegistrar)->cacheVersion();
    }

    public function test_named_lock_returns_callback_result_and_releases_after_exception(): void
    {
        $reader = new PermissionRegistrar;
        $this->assertSame('result', $reader->withLock('same', fn () => 'result'));
        try {
            $reader->withLock('same', fn () => throw new \RuntimeException('callback failed'));
        } catch (\RuntimeException $e) {
            $this->assertSame('callback failed', $e->getMessage());
        }
        $this->assertSame(42, $reader->withLock('same', fn () => 42));
    }

    public function test_nested_team_context_restores_explicit_null_and_dynamic_resolver_after_failure(): void
    {
        $team = 'initial';
        config(['permission.team_resolver' => function () use (&$team) {
            return $team;
        }]);
        $reader = new PermissionRegistrar;
        $this->assertSame('initial', $reader->getTeamId());
        $value = $reader->withTeamId(null, function () use ($reader) {
            $this->assertNull($reader->getTeamId());
            try {
                $reader->withTeamId('inner', function () use ($reader) {
                    $this->assertSame('inner', $reader->getTeamId());
                    throw new \RuntimeException('abort');
                });
            } catch (\RuntimeException) {
                $this->assertNull($reader->getTeamId());
            }

            return 'result';
        });
        $this->assertSame('result', $value);
        $team = 'later';
        $this->assertSame('later', $reader->getTeamId());
        $reader->setTeamId(null);
        $reader->withTeamId('temporary', fn () => null);
        $this->assertNull($reader->getTeamId());
    }
}
