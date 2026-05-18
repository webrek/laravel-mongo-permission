<?php

namespace Webrek\MongoPermission\Contracts;

interface Role
{
    public static function findByName(string $name, ?string $guardName = null): self;

    public static function findById(string $id, ?string $guardName = null): self;

    public function getName(): string;

    public function getGuardName(): string;

    public function givePermissionTo(...$permissions): self;

    public function revokePermissionTo(...$permissions): self;

    public function syncPermissions(...$permissions): self;

    public function hasPermissionTo(string|Permission $permission): bool;
}
