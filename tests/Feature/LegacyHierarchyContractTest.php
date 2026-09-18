<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Event;
use MongoDB\BSON\ObjectId;
use Webrek\MongoPermission\Events\RoleParentChanged;
use Webrek\MongoPermission\Exceptions\RoleHierarchyCycle;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\TestCase;

class LegacyHierarchyContractTest extends TestCase
{
    public function test_native_bson_edges_are_followed_across_multiple_levels(): void
    {
        $root = Role::create(['name' => 'root']);
        $middle = Role::create(['name' => 'middle', 'parent_role_ids' => [new ObjectId($root->id)]]);
        $leaf = Role::create(['name' => 'leaf', 'parent_role_ids' => [new ObjectId($middle->id)]]);
        $this->assertSame(['middle', 'root'], $leaf->getAncestors()->pluck('name')->all());
        $extra = Role::create(['name' => 'extra']);
        $leaf->inheritsFrom($extra);
        $this->assertSame([$middle->id, $extra->id], $leaf->fresh()->parent_role_ids);
    }

    public function test_attaching_and_detaching_native_bson_edges_is_idempotent(): void
    {
        $parent = Role::create(['name' => 'parent']);
        $child = Role::create(['name' => 'child', 'parent_role_ids' => [new ObjectId($parent->id)]]);
        Event::fake([RoleParentChanged::class]);
        $child->inheritsFrom($parent);
        Event::assertNotDispatched(RoleParentChanged::class);
        $this->assertCount(1, $child->fresh()->parent_role_ids);
        $child->stopsInheritingFrom($parent);
        $this->assertSame([], $child->fresh()->parent_role_ids);
        Event::assertDispatchedTimes(RoleParentChanged::class, 1);
    }

    public function test_adding_a_parent_does_not_hide_an_existing_cycle(): void
    {
        $a = Role::create(['name' => 'a']);
        $b = Role::create(['name' => 'b', 'parent_role_ids' => [$a->id]]);
        Role::whereKey($a->id)->update(['parent_role_ids' => [$b->id]]);
        $new = Role::create(['name' => 'new']);
        try {
            $a->inheritsFrom($new);
            $this->fail('Existing cycle was ignored');
        } catch (RoleHierarchyCycle) {
            $this->assertSame([$b->id], $a->fresh()->parent_role_ids);
        }
    }

    public function test_shared_ancestor_memo_avoids_fetching_the_same_roles_again(): void
    {
        $root = Role::create(['name' => 'root']);
        $left = Role::create(['name' => 'left', 'parent_role_ids' => [$root->id]]);
        $right = Role::create(['name' => 'right', 'parent_role_ids' => [$root->id]]);
        $memo = [];
        $this->assertSame(['root'], $left->getAncestors($memo)->pluck('name')->all());
        $reads = 0;
        Role::retrieved(function () use (&$reads): void {
            $reads++;
        });
        $this->assertSame(['root'], $right->getAncestors($memo)->pluck('name')->all());
        $this->assertSame(0, $reads, 'A shared ancestor already in the memo must not be queried again');
    }
}
