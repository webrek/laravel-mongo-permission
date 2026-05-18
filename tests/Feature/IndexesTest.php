<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Tests\TestCase;

class IndexesTest extends TestCase
{
    public function test_creates_unique_indexes_on_roles_and_permissions(): void
    {
        $this->artisan('permission:create-indexes')->assertExitCode(0);

        $db = $this->app['db']->connection('mongodb')->getMongoDB();

        $roleIndexes = iterator_to_array($db->selectCollection('roles')->listIndexes());
        $permIndexes = iterator_to_array($db->selectCollection('permissions')->listIndexes());

        $roleHasUnique = collect($roleIndexes)->contains(function ($i) {
            return $i->isUnique()
                && array_keys((array) $i->getKey()) === ['name', 'guard_name', 'team_id'];
        });
        $permHasUnique = collect($permIndexes)->contains(function ($i) {
            return $i->isUnique()
                && array_keys((array) $i->getKey()) === ['name', 'guard_name', 'team_id'];
        });

        $this->assertTrue($roleHasUnique, 'roles should have unique compound index');
        $this->assertTrue($permHasUnique, 'permissions should have unique compound index');
    }
}
