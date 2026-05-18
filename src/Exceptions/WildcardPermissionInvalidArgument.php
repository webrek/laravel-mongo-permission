<?php

namespace Webrek\MongoPermission\Exceptions;

class WildcardPermissionInvalidArgument extends MongoPermissionException
{
    public static function empty(string $which): self
    {
        return new self("Wildcard permission argument '{$which}' must not be empty.");
    }
}
