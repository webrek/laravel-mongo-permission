<?php

namespace Webrek\MongoPermission\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
    /** @var string[] */
    public array $requiredRoles = [];
    /** @var string[] */
    public array $requiredPermissions = [];

    public static function forRoles(array $roles): self
    {
        $msg = 'User does not have the right roles. Required: ' . implode(', ', $roles);
        $e = new self(403, $msg);
        $e->requiredRoles = $roles;
        return $e;
    }

    public static function forPermissions(array $permissions): self
    {
        $msg = 'User does not have the right permissions. Required: ' . implode(', ', $permissions);
        $e = new self(403, $msg);
        $e->requiredPermissions = $permissions;
        return $e;
    }

    public static function forRolesOrPermissions(array $rolesOrPermissions): self
    {
        $msg = 'User does not have any of the necessary access rights: ' . implode(', ', $rolesOrPermissions);
        $e = new self(403, $msg);
        return $e;
    }

    public static function notLoggedIn(): self
    {
        return new self(403, 'User is not logged in.');
    }
}
