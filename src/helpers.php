<?php

use Webrek\MongoPermission\PermissionRegistrar;

if (! function_exists('setPermissionsTeamId')) {
    function setPermissionsTeamId(?string $teamId): void
    {
        app(PermissionRegistrar::class)->setTeamId($teamId);
    }
}
