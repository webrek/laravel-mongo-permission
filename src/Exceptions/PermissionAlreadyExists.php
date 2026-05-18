<?php

namespace Webrek\MongoPermission\Exceptions;

class PermissionAlreadyExists extends MongoPermissionException
{
    public static function create(string $name, string $guard): self
    {
        return new self("A permission '{$name}' already exists for guard '{$guard}'.");
    }
}
