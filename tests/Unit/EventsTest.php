<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\PermissionCreated;
use Webrek\MongoPermission\Events\PermissionDeleted;
use Webrek\MongoPermission\Events\PermissionDetached;
use Webrek\MongoPermission\Events\RoleAttached;
use Webrek\MongoPermission\Events\RoleCreated;
use Webrek\MongoPermission\Events\RoleDeleted;
use Webrek\MongoPermission\Events\RoleDetached;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class EventsTest extends TestCase
{
    public function test_creating_a_permission_dispatches_permission_created(): void
    {
        Event::fake([PermissionCreated::class]);

        $perm = Permission::create(['name' => 'p1']);

        Event::assertDispatched(PermissionCreated::class, fn ($e) => $e->permission->getName() === 'p1');
    }

    public function test_deleting_a_permission_dispatches_permission_deleted(): void
    {
        $perm = Permission::create(['name' => 'p1']);

        Event::fake([PermissionDeleted::class]);

        $perm->delete();

        Event::assertDispatched(PermissionDeleted::class);
    }

    public function test_creating_a_role_dispatches_role_created(): void
    {
        Event::fake([RoleCreated::class]);

        $role = Role::create(['name' => 'r1']);

        Event::assertDispatched(RoleCreated::class, fn ($e) => $e->role->getName() === 'r1');
    }

    public function test_deleting_a_role_dispatches_role_deleted(): void
    {
        $role = Role::create(['name' => 'r1']);

        Event::fake([RoleDeleted::class]);

        $role->delete();

        Event::assertDispatched(RoleDeleted::class);
    }

    public function test_assign_role_dispatches_role_attached(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);

        Event::fake([RoleAttached::class]);

        $user->assignRole('admin');

        Event::assertDispatched(RoleAttached::class, function ($e) use ($user) {
            return $e->role->getName() === 'admin'
                && $e->user->getKey() === $user->getKey()
                && $e->guard === 'web';
        });
    }

    public function test_remove_role_dispatches_role_detached(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);
        $user->assignRole('admin');

        Event::fake([RoleDetached::class]);

        $user->removeRole('admin');

        Event::assertDispatched(RoleDetached::class);
    }

    public function test_give_permission_to_user_dispatches_permission_attached_with_user_model(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);

        Event::fake([PermissionAttached::class]);

        $user->givePermissionTo('edit');

        Event::assertDispatched(PermissionAttached::class, function ($e) use ($user) {
            return $e->permission->getName() === 'edit'
                && $e->model->getKey() === $user->getKey();
        });
    }

    public function test_revoke_permission_dispatches_permission_detached(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);
        $user->givePermissionTo('edit');

        Event::fake([PermissionDetached::class]);

        $user->revokePermissionTo('edit');

        Event::assertDispatched(PermissionDetached::class);
    }

    public function test_role_give_permission_dispatches_permission_attached_with_role_model(): void
    {
        Permission::create(['name' => 'edit']);
        $role = Role::create(['name' => 'editor']);

        Event::fake([PermissionAttached::class]);

        $role->givePermissionTo('edit');

        Event::assertDispatched(PermissionAttached::class, function ($e) use ($role) {
            return $e->permission->getName() === 'edit'
                && $e->model->getKey() === $role->getKey();
        });
    }
}
