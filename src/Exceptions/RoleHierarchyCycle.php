<?php

namespace Webrek\MongoPermission\Exceptions;

class RoleHierarchyCycle extends MongoPermissionException
{
    public static function detected(string $childName, string $parentName): self
    {
        return new self(sprintf(
            'Cannot make role "%s" inherit from "%s" — this would create a cycle.',
            $childName,
            $parentName,
        ));
    }
}
