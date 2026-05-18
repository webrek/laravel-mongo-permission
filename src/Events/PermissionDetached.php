<?php

namespace Webrek\MongoPermission\Events;

use Webrek\MongoPermission\Contracts\Permission;

class PermissionDetached
{
    public function __construct(
        public readonly mixed $model,
        public readonly Permission $permission,
        public readonly ?string $teamId,
        public readonly string $guard,
    ) {
    }
}
