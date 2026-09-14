<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\TestCase;

class CacheConcurrencyTest extends TestCase
{
    public function test_file_cache_generation_serializes_independent_processes(): void
    {
        $directory = sys_get_temp_dir().'/permission-cache-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        config(['cache.stores.concurrent' => ['driver' => 'file', 'path' => $directory], 'permission.cache.store' => 'concurrent', 'permission.cache.key' => 'worker-test']);
        $registrar = new PermissionRegistrar;
        $before = $registrar->cacheVersion();
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = new Process([PHP_BINARY, __DIR__.'/../Support/cache-worker.php', $directory, 'worker-test', '40']);
                $process->start();
                $workers[] = $process;
            }
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertSame($before + 160, $registrar->cacheVersion());
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            (new Filesystem)->deleteDirectory($directory);
        }
    }
}
