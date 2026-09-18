<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CachePolicyContractTest extends TestCase
{
    public function test_null_ttl_retains_entries_until_the_documented_one_day_boundary(): void
    {
        $this->travelTo(Carbon::parse('2030-01-01'));
        config(['permission.cache.store' => 'array', 'permission.cache.expiration_time' => null]);
        $p = Permission::create(['name' => 'p']);
        $u = TestUser::create(['name' => 'u']);
        $u->givePermissionTo($p);
        $r = new PermissionRegistrar;
        $this->assertSame(['p'], $r->getUserPermissionSlugs($u));
        $key = $r->cacheKey((string) $u->id, null, 'permissions');
        $this->travel(86399)->seconds();
        $this->assertNotNull($r->store()->get($key));
        $this->travel(1)->seconds();
        $this->assertNull($r->store()->get($key));
    }

    public function test_cached_generation_reads_do_not_wait_on_a_writer_lock(): void
    {
        config(['permission.cache.store' => 'array']);
        $r = new PermissionRegistrar;
        $version = $r->cacheVersion();
        $lock = $r->store()->getStore()->lock(config('permission.cache.key').'.version.lock', 10);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame($version, $r->cacheVersion());
        } finally {
            $lock->release();
        }
    }

    public function test_locking_rejects_non_laravel_repositories_before_callback(): void
    {
        $repository = Mockery::mock(CacheContract::class);
        $repository->shouldReceive('getStore')->andReturn(new ArrayStore);
        Cache::shouldReceive('store')->andReturn($repository);
        try {
            (new PermissionRegistrar)->withLock('unsupported', fn () => $this->fail('Callback ran'));
            $this->fail('Unsupported repository accepted');
        } catch (\LogicException $e) {
            $this->assertSame(\LogicException::class, get_class($e));
            $this->assertSame('Permission mutations require a cache store that supports atomic locks.', $e->getMessage());
        }
    }
}
