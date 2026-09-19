<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use MongoDB\BSON\ObjectId;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class BsonKeyRole extends Role
{
    public function getKey()
    {
        return new ObjectId((string) parent::getKey());
    }
}
class BsonKeyPermission extends Permission
{
    public function getKey()
    {
        return new ObjectId((string) parent::getKey());
    }
}
class BsonKeyUser extends TestUser
{
    public function getKey()
    {
        return new ObjectId((string) parent::getKey());
    }
}

class BsonKeyModelTest extends TestCase
{
    public function test_diagnostics_and_legacy_bson_references_work_with_custom_models(): void
    {
        config(['permission.models.role' => BsonKeyRole::class, 'permission.models.permission' => BsonKeyPermission::class, 'auth.providers.users.model' => BsonKeyUser::class]);
        $p = BsonKeyPermission::create(['name' => 'read']);
        $q = BsonKeyPermission::create(['name' => 'other']);
        $parent = BsonKeyRole::create(['name' => 'parent', 'permission_ids' => [$q->getKey()]]);
        $role = BsonKeyRole::create(['name' => 'role', 'permission_ids' => [$p->getKey()], 'parent_role_ids' => [$parent->getKey()]]);
        $u = BsonKeyUser::create(['name' => 'bson', 'email' => 'bson@test', 'role_ids' => [$role->getKey()], 'permission_ids' => [$p->getKey()]]);
        $this->assertSame([(string) $p->getKey(), (string) $q->getKey()], $role->getAllPermissionIds());
        $this->assertTrue($role->hasPermissionTo($p));
        foreach (['read', 'other'] as $name) {
            $this->assertSame(0, Artisan::call('permission:check', ['user_id' => (string) $u->getKey(), 'permission' => $name]));
            $this->assertStringContainsString('  YES', Artisan::output());
            $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => $name]));
            $this->assertStringContainsString('<bson@test>', Artisan::output());
        }
        $this->assertSame(0, Artisan::call('permission:list-users', ['role' => 'role']));
        $this->assertStringContainsString('<bson@test>', Artisan::output());
        $this->assertSame(0, Artisan::call('permission:show'));
        $this->assertMatchesRegularExpression('/\| role\s*\| x\s*\|\s*\|/', Artisan::output());
        $keeper = BsonKeyRole::create(['name' => 'keeper', 'permission_ids' => [$p->getKey(), $q->getKey()]]);
        $structured = BsonKeyUser::create(['name' => 'structured', 'role_ids' => [['role_id' => $role->getKey()]], 'permission_ids' => [['permission_id' => $p->getKey()]]]);
        $role->delete();
        $this->assertFalse($u->hasRole('role'));
        $this->assertEmpty($u->fresh()->role_ids);
        $this->assertEmpty($structured->fresh()->role_ids);
        $p->delete();
        $this->assertEmpty($u->fresh()->permission_ids);
        $this->assertEmpty($structured->fresh()->permission_ids);
        $this->assertSame([(string) $q->getKey()], array_map('strval', $keeper->fresh()->permission_ids));
    }

    public function test_custom_bson_keys_are_normalized_at_grant_and_lookup_boundaries(): void
    {
        config(['permission.models.role' => BsonKeyRole::class, 'permission.models.permission' => BsonKeyPermission::class, 'auth.providers.users.model' => BsonKeyUser::class]);
        $p = BsonKeyPermission::create(['name' => 'p']);
        $q = BsonKeyPermission::create(['name' => 'q']);
        $r = BsonKeyRole::create(['name' => 'r']);
        $parent = BsonKeyRole::create(['name' => 'parent']);
        $u = BsonKeyUser::create(['name' => 'bson']);
        $parent->givePermissionTo($q);
        $r->givePermissionTo($p)->inheritsFrom($parent);
        $u->assignRole($r)->givePermissionTo($q);
        $this->assertTrue($u->hasRole($r));
        $this->assertTrue($u->hasRole('r'));
        $this->assertTrue($u->hasPermissionTo($p));
        $this->assertTrue($u->hasPermissionTo($q));
        $this->assertTrue($u->hasDirectPermission($q));
        $this->assertTrue($r->hasPermissionTo($p));
        $this->assertTrue($r->hasPermissionTo('p'));
        $this->assertSame((string) $p->getKey(), (string) BsonKeyPermission::findById((string) $p->getKey())->getKey());
        $this->assertSame((string) $r->getKey(), (string) BsonKeyRole::findById((string) $r->getKey())->getKey());
        $this->assertSame([(string) $r->getKey()], app(PermissionRegistrar::class)->getUserRoleIds($u));
        $this->assertEqualsCanonicalizing([(string) $p->getKey(), (string) $q->getKey()], app(PermissionRegistrar::class)->getUserPermissionIds($u));
        $r->stopsInheritingFrom($parent);
        $u->revokePermissionTo($q);
        $this->assertFalse($u->hasPermissionTo($q));
        $u->removeRole($r);
        $this->assertFalse($u->hasPermissionTo($p));
    }
}
