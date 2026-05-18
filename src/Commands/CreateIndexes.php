<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;

class CreateIndexes extends Command
{
    protected $signature = 'permission:create-indexes';
    protected $description = 'Create MongoDB indexes for permission and role collections';

    public function handle(): int
    {
        $db = app('db')->connection('mongodb')->getMongoDB();

        $rolesColl = config('permission.collection_names.roles', 'roles');
        $permsColl = config('permission.collection_names.permissions', 'permissions');

        $db->selectCollection($permsColl)->createIndex(
            ['name' => 1, 'guard_name' => 1, 'team_id' => 1],
            ['unique' => true, 'name' => 'uniq_name_guard_team']
        );
        $db->selectCollection($permsColl)->createIndex(
            ['guard_name' => 1],
            ['name' => 'idx_guard']
        );

        $db->selectCollection($rolesColl)->createIndex(
            ['name' => 1, 'guard_name' => 1, 'team_id' => 1],
            ['unique' => true, 'name' => 'uniq_name_guard_team']
        );
        $db->selectCollection($rolesColl)->createIndex(
            ['permission_ids' => 1],
            ['name' => 'idx_permission_ids']
        );

        $this->info('Indexes created.');
        return self::SUCCESS;
    }
}
