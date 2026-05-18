<?php

namespace Webrek\MongoPermission\Events;

use Webrek\MongoPermission\Contracts\Role;

class RoleAttached
{
    public function __construct(
        public readonly mixed $user,
        public readonly Role $role,
        public readonly ?string $teamId,
        public readonly string $guard,
    ) {
    }
}
