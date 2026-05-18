<?php

namespace Webrek\MongoPermission\Tests\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use MongoDB\Laravel\Eloquent\Model;
use Webrek\MongoPermission\Traits\HasPermissions;

class TestUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasPermissions;

    protected $connection = 'mongodb';
    protected $collection = 'users';
    protected $guarded = [];
}
