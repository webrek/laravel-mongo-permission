<?php

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Webrek\MongoPermission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';
$app = new Container;
Container::setInstance($app);
$app->instance('config', new Repository([
    'cache' => ['default' => 'file', 'stores' => ['file' => ['driver' => 'file', 'path' => $argv[1]]]],
    'permission' => ['cache' => ['key' => $argv[2], 'store' => 'default']],
]));
$app->instance('files', new Filesystem);
$app->instance('cache', new CacheManager($app));
Facade::setFacadeApplication($app);
$registrar = new PermissionRegistrar;
for ($i = 0; $i < (int) $argv[3]; $i++) {
    $registrar->bumpCacheVersion();
}
echo $registrar->cacheVersion();
