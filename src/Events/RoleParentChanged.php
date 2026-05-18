<?php

namespace Webrek\MongoPermission\Events;

use Webrek\MongoPermission\Contracts\Role;

class RoleParentChanged
{
    public function __construct(
        public readonly Role $role,
        public readonly Role $parent,
        public readonly string $action, // 'attached' | 'detached'
    ) {}
}
