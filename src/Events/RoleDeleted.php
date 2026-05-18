<?php

namespace Webrek\MongoPermission\Events;

use Webrek\MongoPermission\Contracts\Role;

class RoleDeleted
{
    public function __construct(public readonly Role $role)
    {
    }
}
