<?php

namespace Webrek\MongoPermission\Testing;

use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Drop into your TestCase to get expressive assertions for
 * role and permission state.
 *
 *     use Webrek\MongoPermission\Testing\MongoPermissionAssertions;
 *
 *     class FooTest extends TestCase
 *     {
 *         use MongoPermissionAssertions;
 *
 *         public function test_admin_can_edit(): void
 *         {
 *             $this->assertUserHasRole($user, 'admin');
 *             $this->assertUserHasPermission($user, 'edit articles');
 *         }
 *     }
 */
trait MongoPermissionAssertions
{
    public function assertUserHasRole(object $user, string $role, ?string $guard = null, ?string $message = null): void
    {
        PHPUnit::assertTrue(
            $user->hasRole($role, $guard),
            $message ?? sprintf('Failed asserting that user has role [%s].', $role),
        );
    }

    public function assertUserDoesNotHaveRole(object $user, string $role, ?string $guard = null, ?string $message = null): void
    {
        PHPUnit::assertFalse(
            $user->hasRole($role, $guard),
            $message ?? sprintf('Failed asserting that user does not have role [%s].', $role),
        );
    }

    public function assertUserHasAnyRole(object $user, array $roles, ?string $message = null): void
    {
        PHPUnit::assertTrue(
            $user->hasAnyRole(...$roles),
            $message ?? sprintf('Failed asserting that user has any of roles [%s].', implode(', ', $roles)),
        );
    }

    public function assertUserHasAllRoles(object $user, array $roles, ?string $message = null): void
    {
        PHPUnit::assertTrue(
            $user->hasAllRoles($roles),
            $message ?? sprintf('Failed asserting that user has all roles [%s].', implode(', ', $roles)),
        );
    }

    public function assertUserHasPermission(object $user, string $permission, ?string $message = null): void
    {
        PHPUnit::assertTrue(
            $user->hasPermissionTo($permission),
            $message ?? sprintf('Failed asserting that user has permission [%s].', $permission),
        );
    }

    public function assertUserDoesNotHavePermission(object $user, string $permission, ?string $message = null): void
    {
        try {
            $has = $user->hasPermissionTo($permission);
        } catch (\Webrek\MongoPermission\Exceptions\PermissionDoesNotExist) {
            $has = false;
        }
        PHPUnit::assertFalse(
            $has,
            $message ?? sprintf('Failed asserting that user does not have permission [%s].', $permission),
        );
    }

    public function assertUserHasDirectPermission(object $user, string $permission, ?string $message = null): void
    {
        PHPUnit::assertTrue(
            $user->hasDirectPermission($permission),
            $message ?? sprintf('Failed asserting that user has direct permission [%s].', $permission),
        );
    }

    public function assertRoleHasPermission(object $role, string $permission, ?string $message = null): void
    {
        PHPUnit::assertTrue(
            $role->hasPermissionTo($permission),
            $message ?? sprintf('Failed asserting that role [%s] has permission [%s].', $role->getName(), $permission),
        );
    }

    public function assertRoleDoesNotHavePermission(object $role, string $permission, ?string $message = null): void
    {
        PHPUnit::assertFalse(
            $role->hasPermissionTo($permission),
            $message ?? sprintf('Failed asserting that role [%s] does not have permission [%s].', $role->getName(), $permission),
        );
    }
}
