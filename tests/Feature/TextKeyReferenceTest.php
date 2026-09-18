<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class TextKeyReferenceTest extends TestCase
{
    public function test_non_objectid_string_keys_remain_supported_by_queries_and_deletion(): void
    {
        $p = Permission::create(['_id' => 'permission-external', 'name' => 'read']);
        $r = Role::create(['_id' => 'role-external', 'name' => 'reader']);
        $r->givePermissionTo($p);
        $u = TestUser::create(['name' => 'external', 'email' => 'external@test']);
        $u->assignRole($r)->givePermissionTo($p);
        $this->assertTrue($u->hasPermissionTo($p));
        $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => 'read']));
        $this->assertStringContainsString('<external@test>', Artisan::output());
        $p->delete();
        $this->assertEmpty($u->fresh()->permission_ids);
        $this->assertEmpty($r->fresh()->permission_ids);
        $r->delete();
        $this->assertEmpty($u->fresh()->role_ids);
    }
}
