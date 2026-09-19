<?php

namespace Webrek\MongoPermission\Listeners;

use Webrek\MongoPermission\Events\PermissionAttached;
use Webrek\MongoPermission\Events\PermissionDetached;
use Webrek\MongoPermission\Events\RoleAttached;
use Webrek\MongoPermission\Events\RoleDetached;
use Webrek\MongoPermission\Events\RoleParentChanged;
use Webrek\MongoPermission\PermissionRegistrar;

class RefreshUserCacheListener
{
    public function __construct(protected PermissionRegistrar $registrar) {}

    public function onRoleAttached(RoleAttached $e): void
    {
        $this->forgetForUser($e->user, $e->teamId);
    }

    public function onRoleDetached(RoleDetached $e): void
    {
        $this->forgetForUser($e->user, $e->teamId);
    }

    public function onPermissionAttached(PermissionAttached $e): void
    {
        // model can be a User (direct grant) or a Role (catalog change)
        if (method_exists($e->model, 'hasRole')) {
            $this->forgetForUser($e->model, $e->teamId);
        }
        // Role catalog changes are versioned by the Role model.
    }

    public function onPermissionDetached(PermissionDetached $e): void
    {
        if (method_exists($e->model, 'hasRole')) {
            $this->forgetForUser($e->model, $e->teamId);
        }
    }

    public function onRoleParentChanged(RoleParentChanged $e): void
    {
        // A parent change ripples to every user with this role or any of its
        // descendants. Walking that graph is expensive; flush all package
        // cache and let the next read rebuild fresh.
        $this->registrar->bumpCacheVersion();
    }

    protected function forgetForUser(object $user, ?string $teamId): void
    {
        $this->registrar->forgetUserCache((string) $user->getKey(), $teamId);
    }
}
