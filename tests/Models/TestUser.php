<?php

namespace Webrek\MongoPermission\Tests\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use MongoDB\Laravel\Eloquent\Model;
use Webrek\MongoPermission\Traits\HasRoles;

class TestUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasRoles;

    protected $connection = 'mongodb';
    protected $collection = 'users';
    protected $guarded = [];
}
