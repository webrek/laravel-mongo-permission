<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Exceptions\GuardDoesNotMatch;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class ResolvingUser extends TestUser
{
    public function permissionIdsFor(array $values): array
    {
        return $this->resolvePermissionIds($values);
    }

    public function roleIdsFor(array $values): array
    {
        return $this->resolveRoleIds($values);
    }

    public function roleIdFor($value, ?string $guard = null): string
    {
        return $this->resolveRoleId($value, $guard);
    }
}
class ExtensionCompatibilityTest extends TestCase
{
    public function test_legacy_subclass_resolution_hooks_preserve_order_identity_and_guard_validation(): void
    {
        $a = Permission::create(['name' => 'a']);
        $b = Permission::create(['name' => 'b']);
        $api = Permission::create(['name' => 'api', 'guard_name' => 'api']);
        $r = Role::create(['name' => 'r']);
        $s = Role::create(['name' => 's']);
        $apiRole = Role::create(['name' => 'api', 'guard_name' => 'api']);
        $u = new ResolvingUser;
        $this->assertSame([(string) $a->id, (string) $b->id], $u->permissionIdsFor([$a, 'b']));
        $this->assertSame([(string) $r->id, (string) $s->id], $u->roleIdsFor([$r, 's']));
        $this->assertSame((string) $apiRole->id, $u->roleIdFor($apiRole, 'api'));
        $this->assertSame((string) $apiRole->id, $u->roleIdFor('api', 'api'));
        $this->assertSame([], $u->roleIdsFor([]));
        $this->assertSame([], $u->permissionIdsFor([]));
        foreach ([fn () => $u->permissionIdsFor([$api]), fn () => $u->roleIdsFor([$apiRole])] as $operation) {
            try {
                $operation();
                $this->fail('Mismatched guard accepted');
            } catch (GuardDoesNotMatch $e) {
                $this->assertStringContainsString('api', $e->getMessage());
                $this->assertStringContainsString('web', $e->getMessage());
            }
        }
    }
}
