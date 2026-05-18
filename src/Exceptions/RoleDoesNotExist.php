<?php

namespace Webrek\MongoPermission\Exceptions;

class RoleDoesNotExist extends MongoPermissionException
{
    public static function named(string $name, string $guard): self
    {
        return new self("There is no role named '{$name}' for guard '{$guard}'.");
    }

    public static function withId(string $id, string $guard): self
    {
        return new self("There is no role with id '{$id}' for guard '{$guard}'.");
    }
}
