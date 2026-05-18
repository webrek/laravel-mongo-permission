<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;
use Webrek\MongoPermission\PermissionRegistrar;

class CacheReset extends Command
{
    protected $signature = 'permission:cache-reset';
    protected $description = 'Flush the mongo-permission cache namespace';

    public function handle(PermissionRegistrar $registrar): int
    {
        $registrar->flush();
        $this->info('Permission cache flushed.');
        return self::SUCCESS;
    }
}
