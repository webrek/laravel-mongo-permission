<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\TestCase;

class CacheLeaseTest extends TestCase
{
    public function test_a_writer_resuming_after_its_lease_expires_preserves_later_increments(): void
    {
        $store = new class extends ArrayStore
        {
            public ?\Closure $afterRead = null;

            public function get($key)
            {
                $value = parent::get($key);
                if ($callback = $this->afterRead) {
                    $this->afterRead = null;
                    $callback($key);
                }

                return $value;
            }
        };
        $repository = new Repository($store);
        $this->app['cache']->extend('lease-test', fn () => $repository);
        config(['cache.stores.lease-test' => ['driver' => 'lease-test'], 'permission.cache.store' => 'lease-test']);
        $writer = new PermissionRegistrar;
        $before = $writer->cacheVersion();
        $store->afterRead = function () use ($store): void {
            // The first read is the fast-path check, the second occurs under the lease.
            $store->afterRead = function ($key) use ($store): void {
                // A paused worker resumes after its lease expires and a second writer commits.
                $store->lock($key.'.lock')->forceRelease();
                (new PermissionRegistrar)->bumpCacheVersion();
            };
        };
        $writer->bumpCacheVersion();
        $this->assertSame($before + 2, $writer->cacheVersion());
    }
}
