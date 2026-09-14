<?php

namespace Webrek\MongoPermission\Support;

use Webrek\MongoPermission\Exceptions\GuardDoesNotMatch;
use Webrek\MongoPermission\Exceptions\TeamDoesNotMatch;
use Webrek\MongoPermission\PermissionRegistrar;

class TeamScope
{
    public static function active(): ?string
    {
        return config('permission.teams', false) ? app(PermissionRegistrar::class)->getTeamId() : null;
    }

    public static function grant(?string $team): bool
    {
        return ! config('permission.teams', false) || $team === self::active()
            || (! config('permission.strict_team_isolation', false) && $team === null);
    }

    public static function owned(?string $team): bool
    {
        return ! config('permission.teams', false) || $team === self::active();
    }

    public static function catalog(object $model, ?string $team = null): bool
    {
        return ! config('permission.teams', false) || $model->team_id === null || $model->team_id === $team;
    }

    public static function validate(object $model, string $guard, ?string $team): void
    {
        if ($model->guard_name !== $guard) {
            throw GuardDoesNotMatch::create($model->guard_name, $guard);
        }
        if (! self::catalog($model, $team)) {
            throw new TeamDoesNotMatch('The role or permission belongs to a different team.');
        }
    }
}
