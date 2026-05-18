<?php

namespace Webrek\MongoPermission\Exceptions;

class PermissionDoesNotExist extends MongoPermissionException
{
    public static function named(string $name, string $guard): self
    {
        return new self("There is no permission named '{$name}' for guard '{$guard}'.");
    }

    public static function withId(string $id, string $guard): self
    {
        return new self("There is no permission with id '{$id}' for guard '{$guard}'.");
    }
}
