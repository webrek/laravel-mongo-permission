<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\TestCase;

class HierarchyLockContractTest extends TestCase
{
    public function test_attaching_and_detaching_coordinate_on_the_same_lock_for_each_guard(): void
    {
        $registrar = new class extends PermissionRegistrar
        {
            public array $requestedLocks = [];

            public function withLock(string $name, callable $callback): mixed
            {
                $this->requestedLocks[] = $name;

                return parent::withLock($name, $callback);
            }
        };
        $this->app->instance(PermissionRegistrar::class, $registrar);
        foreach (['web', 'api'] as $guard) {
            $parent = Role::create(['name' => 'parent', 'guard_name' => $guard]);
            $child = Role::create(['name' => 'child', 'guard_name' => $guard]);
            $child->inheritsFrom($parent);
            $child->stopsInheritingFrom($parent);
            $this->assertEmpty($child->fresh()->parent_role_ids);
        }
        $this->assertCount(4, $registrar->requestedLocks);
        [$attachWeb,$detachWeb,$attachApi,$detachApi] = $registrar->requestedLocks;
        $this->assertSame($attachWeb, $detachWeb, 'Opposite hierarchy mutations must participate in the same mutex');
        $this->assertSame($attachApi, $detachApi);
        $this->assertNotSame($attachWeb, $attachApi, 'Independent guard graphs should not contend');
    }
}
