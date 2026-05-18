<?php

namespace Webrek\MongoPermission\Contracts;

interface Permission
{
    public static function findByName(string $name, ?string $guardName = null): self;

    public static function findById(string $id, ?string $guardName = null): self;

    public function getName(): string;

    public function getGuardName(): string;
}
