<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CacheTest extends TestCase
{
    public function test_user_permission_slugs_are_cached_under_namespaced_key(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);
        $user->givePermissionTo('edit');

        $user->fresh()->hasPermissionTo('edit'); // populates the cache

        $key = sprintf(
            'mongo-permission.user.%s.team.%s.permissions',
            $user->getKey(),
            'null',
        );
        $cached = Cache::get($key);

        $this->assertIsArray($cached);
        $this->assertContains('edit', $cached);
    }

    public function test_subsequent_has_permission_to_does_not_hit_mongo(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);
        $user->givePermissionTo('edit');

        $user = $user->fresh();
        $user->hasPermissionTo('edit'); // warms

        // Now drop the permission_ids field on disk directly to prove subsequent calls
        // do not re-query the user document.
        TestUser::query()
            ->getConnection()
            ->getMongoDB()
            ->selectCollection($user->getTable())
            ->updateOne(['_id' => $user->getKey()], ['$set' => ['permission_ids' => []]]);

        // With cache hot, the answer remains true even after the underlying doc changed.
        $this->assertTrue($user->hasPermissionTo('edit'));
    }
}
