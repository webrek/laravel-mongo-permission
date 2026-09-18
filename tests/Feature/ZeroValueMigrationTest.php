<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\SqlMigrationTestCase;

class ZeroValueMigrationTest extends SqlMigrationTestCase
{
    public function test_zero_identifiers_and_matching_values_are_valid_but_null_matches_are_not(): void
    {
        $this->seedSampleData();
        $sql = DB::connection('spatie_sql');
        $sql->table('users')->insert([['id' => 0, 'name' => '0', 'email' => 'zero@test'], ['id' => 102, 'name' => null, 'email' => 'null@test']]);
        foreach ([0, 102] as $id) {
            $sql->table('model_has_roles')->insert(['role_id' => 10, 'model_id' => $id, 'model_type' => 'App\\Models\\User', 'team_id' => null]);
            $sql->table('model_has_permissions')->insert(['permission_id' => 2, 'model_id' => $id, 'model_type' => 'App\\Models\\User', 'team_id' => null]);
        }
        $zero = TestUser::create(['name' => '0', 'email' => 'zero@test']);
        $null = TestUser::create(['name' => null, 'email' => 'null@test']);
        $this->runMigrate(['--match-by' => 'name']);
        $this->assertTrue($zero->hasRole('editor'));
        $this->assertTrue($zero->fresh()->hasDirectPermission('delete articles'));
        $this->assertFalse($null->hasRole('editor'));
        $this->assertFalse($null->fresh()->hasDirectPermission('delete articles'));
    }
}
