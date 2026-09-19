<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Webrek\MongoPermission\Events\RoleParentChanged;
use Webrek\MongoPermission\Exceptions\RoleHierarchyTooDeep;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\TestCase;

class HierarchyBoundaryTest extends TestCase
{
    public function test_longest_branch_limits_descendants_and_rejection_does_not_modify_graph(): void
    {
        config(['permission.role_hierarchy_max_depth' => 2]);
        $root = Role::create(['name' => 'root']);
        $short = Role::create(['name' => 'short']);
        $middle = Role::create(['name' => 'middle']);
        $leaf = Role::create(['name' => 'leaf']);
        $extension = Role::create(['name' => 'extension']);
        $middle->inheritsFrom($root);
        $leaf->inheritsFrom($middle)->inheritsFrom($short);
        try {
            $root->inheritsFrom($extension);
            $this->fail('Extending the ancestor must not bypass the leaf depth limit');
        } catch (RoleHierarchyTooDeep) {
            $this->assertEmpty($root->fresh()->parent_role_ids ?? []);
            $this->assertEqualsCanonicalizing(['middle', 'root', 'short'], $leaf->getAncestors()->pluck('name')->all());
        }
    }

    public function test_unrelated_legacy_deep_graph_does_not_block_valid_attachment(): void
    {
        config(['permission.role_hierarchy_max_depth' => 1]);
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b', 'parent_role_ids' => [(string) $a->id]]);
        Role::create(['name' => 'c', 'parent_role_ids' => [(string) $b->id]]);
        $x = Role::create(['name' => 'x']);
        $y = Role::create(['name' => 'y']);
        $x->inheritsFrom($y);
        $this->assertSame([(string) $y->id], $x->fresh()->parent_role_ids);
    }

    public function test_stale_parent_edits_preserve_other_edges_and_emit_only_real_changes(): void
    {
        $child = Role::create(['name' => 'child']);
        $stale = $child->fresh();
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b']);
        Event::fake([RoleParentChanged::class]);
        $child->inheritsFrom($a);
        $stale->inheritsFrom($b);
        $stale->inheritsFrom($b);
        Event::assertDispatchedTimes(RoleParentChanged::class, 2);
        $child->stopsInheritingFrom($a);
        $child->stopsInheritingFrom($a);
        Event::assertDispatchedTimes(RoleParentChanged::class, 3);
        $this->assertSame([(string) $b->id], $child->fresh()->parent_role_ids);
    }

    public function test_legacy_cycle_missing_parent_and_depth_limit_are_safe_to_read(): void
    {
        $p = Permission::create(['name' => 'read']);
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b', 'parent_role_ids' => [(string) $a->id]]);
        $a->parent_role_ids = [(string) $b->id, 'missing'];
        $a->save();
        $b->givePermissionTo($p);
        $this->assertSame(['b'], $a->getAncestors()->pluck('name')->all());
        $this->assertSame([(string) $p->id], $a->getAllPermissionIds());
        config(['permission.role_hierarchy_max_depth' => 0]);
        $this->assertEmpty($a->getAncestors()->all());
        $this->assertEmpty($a->getAllPermissionIds());
    }
}
