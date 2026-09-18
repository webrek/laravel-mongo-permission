<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\PermissionDetached;
use Webrek\MongoPermission\Exceptions\PermissionDoesNotExist;
use Webrek\MongoPermission\Exceptions\RoleDoesNotExist;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\TestCase;

class ModelContractTest extends TestCase
{
    public function test_id_lookup_scopes_guard_and_team_returns_clones_and_reports_missing_ids(): void
    {
        foreach ([Role::class, Permission::class] as $class) {
            $web = $class::create(['name' => 'web']);
            $api = $class::create(['name' => 'api', 'guard_name' => 'api']);
            $id = (string) $web->id;
            $this->assertTrue($web->is($class::findById($id)));
            $this->assertTrue($api->is($class::findById((string) $api->id, 'api')));
            $copy = $class::findById($id);
            $copy->name = 'unsaved';
            $this->assertSame('web', $class::findById($id)->name);
            foreach (['web', 'api'] as $guard) {
                $wrongId = $guard === 'web' ? (string) $api->id : $id;
                try {
                    $class::findById($wrongId, $guard);
                    $this->fail('Wrong guard must not resolve');
                } catch (RoleDoesNotExist|PermissionDoesNotExist $e) {
                    $this->assertStringContainsString("with id '{$wrongId}' for guard '{$guard}'", $e->getMessage());
                }
            }
        }
    }

    public function test_catalog_results_are_independent_editable_instances(): void
    {
        foreach ([Role::class, Permission::class] as $class) {
            $model = $class::create(['name' => 'original']);
            $a = $class::findByName('original');
            $a->name = 'unsaved';
            $b = $class::findByName('original');
            $this->assertNotSame($a, $b);
            $this->assertSame('original', $b->name);
            $this->assertSame('original', $model->fresh()->name);
        }
    }

    public function test_null_catalog_team_uses_active_team_but_explicit_team_is_preserved(): void
    {
        config(['permission.teams' => true]);
        setPermissionsTeamId('active');
        foreach ([Role::class, Permission::class] as $class) {
            $this->assertSame('active', $class::create(['name' => 'implicit-null', 'team_id' => null])->team_id);
            $this->assertSame('explicit', $class::create(['name' => 'explicit', 'team_id' => 'explicit'])->team_id);
        }
    }

    public function test_role_permission_mutations_deduplicate_preserve_dense_lists_and_emit_only_changes(): void
    {
        $a = Permission::create(['name' => 'a']);
        $b = Permission::create(['name' => 'b']);
        $c = Permission::create(['name' => 'c']);
        $r = Role::create(['name' => 'role']);
        $r->givePermissionTo($a, $b);
        Event::fake([PermissionAttached::class, PermissionDetached::class]);
        $r->givePermissionTo([$a, $c], [$c]);
        $this->assertSame([(string) $a->id, (string) $b->id, (string) $c->id], $r->fresh()->permission_ids);
        $r->revokePermissionTo($a);
        $this->assertSame([(string) $b->id, (string) $c->id], $r->fresh()->permission_ids);
        $r->syncPermissions($c);
        $this->assertSame([(string) $c->id], $r->fresh()->permission_ids);
        Event::assertDispatchedTimes(PermissionAttached::class, 1);
        Event::assertDispatchedTimes(PermissionDetached::class, 2);
        Event::assertDispatched(PermissionAttached::class, fn ($e) => $e->permission->is($c));
        Event::assertDispatched(PermissionDetached::class, fn ($e) => $e->permission->is($b));
    }

    public function test_ancestor_walk_continues_after_duplicate_missing_and_wrong_guard_nodes(): void
    {
        $good = Role::create(['name' => 'good']);
        $bad = Role::create(['name' => 'bad', 'guard_name' => 'api']);
        $r = Role::create(['name' => 'r']);
        $r->parent_role_ids = [(string) $r->id, 'missing', (string) $bad->id, (string) $good->id];
        $r->save();
        $this->assertSame([(string) $good->id], $r->getAncestors()->pluck('id')->all());
        $memo = [];
        $r->getAncestors($memo);
        $this->assertArrayHasKey('missing', $memo);
        $this->assertNull($memo['missing']);
    }

    public function test_read_depth_limit_stops_at_boundary_even_for_legacy_overdeep_graph(): void
    {
        config(['permission.role_hierarchy_max_depth' => 2]);
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b', 'parent_role_ids' => [(string) $a->id]]);
        $c = Role::create(['name' => 'c', 'parent_role_ids' => [(string) $b->id]]);
        $d = Role::create(['name' => 'd', 'parent_role_ids' => [(string) $c->id]]);
        $this->assertSame([(string) $c->id, (string) $b->id], $d->getAncestors()->pluck('id')->all());
    }

    public function test_own_and_multiple_inherited_permissions_form_one_dense_unique_list(): void
    {
        $p = Permission::create(['name' => 'own']);
        $q = Permission::create(['name' => 'shared']);
        $z = Permission::create(['name' => 'other']);
        $a = Role::create(['name' => 'a', 'permission_ids' => [(string) $q->id]]);
        $b = Role::create(['name' => 'b', 'permission_ids' => [(string) $q->id, (string) $z->id]]);
        $r = Role::create(['name' => 'r', 'permission_ids' => [(string) $p->id, (string) $q->id]]);
        $r->inheritsFrom($a)->inheritsFrom($b);
        $this->assertSame([(string) $p->id, (string) $q->id, (string) $z->id], $r->getAllPermissionIds());
    }
}
