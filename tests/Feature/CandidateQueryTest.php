<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class CandidateQueryTest extends TestCase
{
    public function test_permission_listing_does_not_fetch_unrelated_user_batches(): void
    {
        $p = Permission::create(['name' => 'read']);
        $u = TestUser::create(['name' => 'matched', 'email' => 'matched@test']);
        $u->givePermissionTo($p);
        $model = new TestUser;
        $table = $model->getTable();
        $model->getConnection()->getMongoDB()->selectCollection($table)->insertMany(array_fill(0, 400, ['name' => 'unrelated', 'role_ids' => [], 'permission_ids' => []]));
        Artisan::call('permission:create-indexes');
        $monitor = new class($table) implements CommandSubscriber
        {
            public int $extraBatches = 0;

            public function __construct(private string $table) {}

            public function commandStarted(CommandStartedEvent $event): void
            {
                if ($event->getCommandName() === 'getMore' && ($event->getCommand()->collection ?? null) === $this->table) {
                    $this->extraBatches++;
                }
            }

            public function commandSucceeded(CommandSucceededEvent $event): void {}

            public function commandFailed(CommandFailedEvent $event): void {}
        };
        \MongoDB\Driver\Monitoring\addSubscriber($monitor);
        try {
            $this->assertSame(0, Artisan::call('permission:list-users', ['--permission' => 'read']));
            $output = Artisan::output();
            $this->assertStringContainsString('1 user(s)', $output);
            $this->assertStringContainsString('<matched@test>', $output);
            $this->assertSame(0, $monitor->extraBatches, 'The candidate query must not stream unrelated users');
        } finally {
            \MongoDB\Driver\Monitoring\removeSubscriber($monitor);
        }
    }
}
