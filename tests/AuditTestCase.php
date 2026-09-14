<?php

namespace Webrek\MongoPermission\Tests;

use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;

abstract class AuditTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['permission.teams' => false]);
        foreach (['articles.view', 'articles.create', 'articles.publish', 'articles.delete', 'users.manage', 'roles.manage', 'permissions.manage'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['admin' => Permission::pluck('name')->all(), 'editor' => ['articles.view', 'articles.create'], 'lector' => ['articles.view']] as $name => $permissions) {
            $role = Role::create(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $user = TestUser::create(['name' => ucfirst($name), 'email' => $name.'@permission.test']);
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->flush();
    }

    protected function user(string $name): TestUser
    {
        return TestUser::where('email', $name.'@permission.test')->firstOrFail();
    }
}
