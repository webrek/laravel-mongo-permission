<?php

namespace Webrek\MongoPermission\Events;

use Webrek\MongoPermission\Contracts\Permission;

class PermissionDeleted
{
    public function __construct(public readonly Permission $permission)
    {
    }
}
