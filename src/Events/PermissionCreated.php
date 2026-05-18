<?php

namespace Webrek\MongoPermission\Events;

use Webrek\MongoPermission\Contracts\Permission;

class PermissionCreated
{
    public function __construct(public readonly Permission $permission)
    {
    }
}
