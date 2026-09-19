<?php

use Webrek\MongoPermission\Tests\Models\TestUser as User;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';
// Boot the same Testbench configuration without setUp(), which deletes test data.
$harness = new class('worker') extends \Webrek\MongoPermission\Tests\TestCase {};
$app = $harness->createApplication();
if (config('cache.default') !== 'redis' || ! str_ends_with(config('database.connections.mongodb.database'), '_test')) {
    throw new RuntimeException('Redis worker requires isolated test configuration');
}
config(['permission.teams' => false]);
$registrar = app(PermissionRegistrar::class);
switch ($argv[1]) {
    case 'increment':
        for ($i = 0; $i < 40; $i++) {
            $registrar->bumpCacheVersion();
        }
        break;
    case 'grant-role-permission':
        Role::findByName('editor')->givePermissionTo('articles.publish');
        break;
    case 'revoke-role-permission':
        Role::findByName('editor')->revokePermissionTo('articles.publish');
        break;
    case 'grant-direct':
        User::where('email', 'lector@permission.test')->firstOrFail()->givePermissionTo('articles.publish');
        break;
    case 'revoke-direct':
        User::where('email', 'lector@permission.test')->firstOrFail()->revokePermissionTo('articles.publish');
        break;
    default:
        throw new InvalidArgumentException('Unknown action');
}
echo "ok\n";
