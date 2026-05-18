<?php

namespace Webrek\MongoPermission\Events;

use Webrek\MongoPermission\Contracts\Permission;

class PermissionAttached
{
    public function __construct(
        public readonly mixed $model,        // User instance or Role instance
        public readonly Permission $permission,
        public readonly ?string $teamId,
        public readonly string $guard,
    ) {
    }
}
