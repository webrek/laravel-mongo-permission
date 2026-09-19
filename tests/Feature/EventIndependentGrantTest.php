<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Webrek\MongoPermission\Events\PermissionDeleted;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class EventIndependentGrantTest extends TestCase
{
    public function test_api_mutations_invalidate_warm_access_when_consumers_disable_event_dispatch(): void
    {
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        $r->givePermissionTo($p);
        $u = TestUser::create(['name' => 'user']);
        $this->assertFalse($u->hasPermissionTo($p));
        Event::fake();
        $u->givePermissionTo($p);
        $this->assertTrue($u->hasPermissionTo($p));
        $u->revokePermissionTo($p);
        $this->assertFalse($u->hasPermissionTo($p));
        $u->assignRole($r);
        $this->assertTrue($u->hasPermissionTo($p));
        $u->removeRole($r);
        $this->assertFalse($u->hasPermissionTo($p));
    }

    public function test_deletion_invalidates_cached_grants_when_package_event_is_suppressed(): void
    {
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        $r->givePermissionTo($p);
        $u = TestUser::create(['name' => 'user']);
        $u->assignRole($r);
        $reader = new PermissionRegistrar;
        $this->assertSame(['p'], $reader->getUserPermissionSlugs($u));
        Event::fake([PermissionDeleted::class]);
        $p->delete();
        $this->assertSame([], $reader->getUserPermissionSlugs($u));
    }
}
