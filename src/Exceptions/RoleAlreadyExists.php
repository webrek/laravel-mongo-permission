<?php

namespace Webrek\MongoPermission\Exceptions;

class RoleAlreadyExists extends MongoPermissionException
{
    public static function create(string $name, string $guard): self
    {
        return new self("A role '{$name}' already exists for guard '{$guard}'.");
    }
}
