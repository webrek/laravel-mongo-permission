<?php

namespace Webrek\MongoPermission\Exceptions;

class RoleHierarchyTooDeep extends MongoPermissionException
{
    public static function exceeded(string $childName, int $maxDepth): self
    {
        return new self(sprintf(
            'Role "%s" would exceed the configured maximum inheritance depth of %d.',
            $childName,
            $maxDepth,
        ));
    }
}
