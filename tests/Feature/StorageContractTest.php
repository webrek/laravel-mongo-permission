<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webrek\MongoPermission\Support\AtomicArray;
use Webrek\MongoPermission\Tests\SqlMigrationTestCase;

class SqlUserRecord extends Model
{
    protected $connection = 'spatie_sql';

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['role_ids' => 'array'];

    public $timestamps = false;
}
class StorageContractTest extends SqlMigrationTestCase
{
    public function test_indexes_include_reverse_role_and_guard_queries_without_a_mongo_user_provider(): void
    {
        foreach ([null, SqlUserRecord::class] as $userClass) {
            config(['auth.providers.users.model' => $userClass]);
            $this->assertSame(0, Artisan::call('permission:create-indexes'));
        }
        $db = DB::connection('mongodb')->getMongoDB();
        foreach (['permissions' => ['idx_guard' => 'guard_name'], 'roles' => ['idx_permission_ids' => 'permission_ids']] as $collection => $expected) {
            $indexes = collect(iterator_to_array($db->selectCollection($collection)->listIndexes()))->keyBy(fn ($index) => $index->getName());
            foreach ($expected as $name => $field) {
                $this->assertSame([$field => 1], (array) $indexes[$name]->getKey());
            }
        }
    }

    public function test_atomic_grants_reject_sql_models_before_writing(): void
    {
        DB::connection('spatie_sql')->statement('ALTER TABLE users ADD COLUMN role_ids TEXT');
        $user = SqlUserRecord::create(['name' => 'sql', 'email' => 'sql@test', 'role_ids' => []]);
        try {
            AtomicArray::mutate($user, 'role_ids', fn () => ['new']);
            $this->fail('SQL writes must be rejected');
        } catch (\LogicException $e) {
            $this->assertSame('Grant mutations require a MongoDB model.', $e->getMessage());
        }
        $this->assertSame([], $user->fresh()->role_ids);
    }
}
