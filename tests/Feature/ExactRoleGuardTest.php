<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class ExactRoleGuardTest extends TestCase
{
    public function test_exact_roles_counts_only_the_requested_guard(): void
    {
        $a = Role::create(['name' => 'web-a']);
        $b = Role::create(['name' => 'web-b']);
        $api = Role::create(['name' => 'api', 'guard_name' => 'api']);
        $user = TestUser::create(['name' => 'mixed', 'role_ids' => [(string) $a->id, (string) $b->id, (string) $api->id]]);
        $this->assertTrue($user->hasExactRoles([$api], 'api'));
        $this->assertTrue($user->hasExactRoles([$a, $b], 'web'));
        $this->assertFalse($user->hasExactRoles([$a], 'web'));
        $this->assertFalse($user->hasExactRoles([$a, $b], 'api'));
    }
}
