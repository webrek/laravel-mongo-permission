<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class PrunedCacheTest extends TestCase
{
    public function test_pruned_grants_cannot_reappear_from_cache_after_clock_correction(): void
    {
        $start = Carbon::parse('2030-01-01');
        $this->travelTo($start);
        $p = Permission::create(['name' => 'temporary']);
        $u = TestUser::create(['name' => 'cached']);
        $u->givePermissionToUntil($p, $start->copy()->addSecond());
        $this->assertTrue($u->hasPermissionTo($p));
        $this->travelTo($start->copy()->addSeconds(2));
        $this->assertSame(0, Artisan::call('permission:prune-expired'));
        $this->travelTo($start);
        $this->assertFalse($u->hasPermissionTo($p));
    }
}
