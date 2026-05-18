<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Exceptions\RoleHierarchyCycle;
use Webrek\MongoPermission\Exceptions\RoleHierarchyTooDeep;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class RoleHierarchyTest extends TestCase
{
    public function test_role_inherits_parent_permissions(): void
    {
        Permission::create(['name' => 'view']);
        $viewer = Role::create(['name' => 'viewer']);
        $viewer->givePermissionTo('view');

        $editor = Role::create(['name' => 'editor']);
        $editor->inheritsFrom($viewer);

        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('editor');

        $this->assertTrue($user->fresh()->hasPermissionTo('view'));
    }

    public function test_transitive_inheritance(): void
    {
        Permission::create(['name' => 'view']);
        $viewer = Role::create(['name' => 'viewer']);
        $viewer->givePermissionTo('view');

        $editor = Role::create(['name' => 'editor']);
        $editor->inheritsFrom($viewer);

        $admin = Role::create(['name' => 'admin']);
        $admin->inheritsFrom($editor);

        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('admin');

        $this->assertTrue($user->fresh()->hasPermissionTo('view'));
    }

    public function test_cycle_detection_throws(): void
    {
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b']);
        $b->inheritsFrom($a);

        $this->expectException(RoleHierarchyCycle::class);
        $a->inheritsFrom($b);
    }

    public function test_self_inheritance_throws(): void
    {
        $a = Role::create(['name' => 'a']);

        $this->expectException(RoleHierarchyCycle::class);
        $a->inheritsFrom($a);
    }

    public function test_max_depth_throws(): void
    {
        config()->set('permission.role_hierarchy_max_depth', 3);

        $r0 = Role::create(['name' => 'r0']);
        $r1 = Role::create(['name' => 'r1']);
        $r2 = Role::create(['name' => 'r2']);
        $r3 = Role::create(['name' => 'r3']);

        $r1->inheritsFrom($r0);
        $r2->inheritsFrom($r1);
        $r3->inheritsFrom($r2);

        $r4 = Role::create(['name' => 'r4']);
        $this->expectException(RoleHierarchyTooDeep::class);
        $r4->inheritsFrom($r3);
    }

    public function test_hasDirectPermission_ignores_inherited(): void
    {
        Permission::create(['name' => 'view']);
        $viewer = Role::create(['name' => 'viewer']);
        $viewer->givePermissionTo('view');

        $editor = Role::create(['name' => 'editor']);
        $editor->inheritsFrom($viewer);

        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('editor');

        $this->assertTrue($user->fresh()->hasPermissionTo('view'));
        $this->assertFalse($user->fresh()->hasDirectPermission('view'));
    }

    public function test_stopsInheritingFrom_revokes_transitively(): void
    {
        Permission::create(['name' => 'view']);
        $viewer = Role::create(['name' => 'viewer']);
        $viewer->givePermissionTo('view');

        $editor = Role::create(['name' => 'editor']);
        $editor->inheritsFrom($viewer);

        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('editor');
        $this->assertTrue($user->fresh()->hasPermissionTo('view'));

        $editor->stopsInheritingFrom($viewer);
        $this->assertFalse($user->fresh()->hasPermissionTo('view'));
    }

    public function test_diamond_inheritance_resolves_once(): void
    {
        Permission::create(['name' => 'view']);
        $base = Role::create(['name' => 'base']);
        $base->givePermissionTo('view');

        $left = Role::create(['name' => 'left']);
        $left->inheritsFrom($base);

        $right = Role::create(['name' => 'right']);
        $right->inheritsFrom($base);

        $top = Role::create(['name' => 'top']);
        $top->inheritsFrom($left);
        $top->inheritsFrom($right);

        $user = TestUser::create(['name' => 'V']);
        $user->assignRole('top');

        $this->assertTrue($user->fresh()->hasPermissionTo('view'));
        $this->assertCount(1, $user->fresh()->getAllPermissions());
    }

    public function test_getAncestors_returns_full_chain(): void
    {
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b']);
        $c = Role::create(['name' => 'c']);

        $b->inheritsFrom($a);
        $c->inheritsFrom($b);

        $names = $c->getAncestors()->pluck('name')->all();
        $this->assertContains('a', $names);
        $this->assertContains('b', $names);
    }

    public function test_inheritsFrom_is_idempotent(): void
    {
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b']);

        $b->inheritsFrom($a);
        $b->inheritsFrom($a);

        $this->assertCount(1, $b->fresh()->parent_role_ids ?? []);
    }
}
