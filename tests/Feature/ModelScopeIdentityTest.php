<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class ModelScopeIdentityTest extends TestCase
{
    public function test_unsaved_changes_to_a_passed_models_scope_do_not_bypass_scope_validation(): void
    {
        config(['permission.teams' => true]);
        setPermissionsTeamId('A');
        $p = Permission::create(['name' => 'p']);
        $r = Role::create(['name' => 'r']);
        $u = TestUser::create(['name' => 'u']);
        $u->givePermissionTo($p)->assignRole($r);
        $this->assertTrue($u->hasPermissionTo($p));
        $this->assertTrue($u->hasRole($r));
        foreach (['guard_name' => 'api', 'team_id' => 'B'] as $field => $value) {
            $permission = clone $p;
            $permission->$field = $value;
            $role = clone $r;
            $role->$field = $value;
            $this->assertFalse($u->hasPermissionTo($permission));
            $this->assertFalse($u->hasDirectPermission($permission));
            $this->assertFalse($u->hasRole($role));
        }
    }
}
