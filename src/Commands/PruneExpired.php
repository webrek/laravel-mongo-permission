<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\Expiry;

class PruneExpired extends Command
{
    protected $signature = 'permission:prune-expired
        {--user-model= : Fully-qualified User model to scan. Defaults to auth.providers.users.model.}
        {--dry-run : Report what would be removed without writing.}';

    protected $description = 'Remove expired role and permission grants from every user document';

    public function handle(): int
    {
        $userClass = $this->option('user-model') ?: config('auth.providers.users.model');
        if (! $userClass || ! class_exists($userClass)) {
            $this->error('Could not resolve a user model. Pass --user-model= or set auth.providers.users.model.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $rolesPruned = 0;
        $permsPruned = 0;
        $usersTouched = 0;

        foreach ($userClass::query()->cursor() as $user) {
            $originalRoles = $user->role_ids ?? [];
            $originalPerms = $user->permission_ids ?? [];

            $keptRoles = array_values(array_filter(
                $originalRoles,
                fn ($e) => ! Expiry::isExpired((array) $e, $now),
            ));
            $keptPerms = array_values(array_filter(
                $originalPerms,
                fn ($e) => ! Expiry::isExpired((array) $e, $now),
            ));

            $rolesRemoved = count($originalRoles) - count($keptRoles);
            $permsRemoved = count($originalPerms) - count($keptPerms);

            if ($rolesRemoved === 0 && $permsRemoved === 0) {
                continue;
            }

            $rolesPruned += $rolesRemoved;
            $permsPruned += $permsRemoved;
            $usersTouched++;

            if ($dryRun) {
                continue;
            }

            $user->role_ids = $keptRoles;
            $user->permission_ids = $keptPerms;
            $user->save();

            app(PermissionRegistrar::class)->forgetUserCache(
                (string) $user->getKey(),
                null,
            );
        }

        $prefix = $dryRun ? 'Dry run: would prune' : 'Pruned';
        $this->info(sprintf(
            '%s %d role grant(s) and %d permission grant(s) across %d user(s).',
            $prefix,
            $rolesPruned,
            $permsPruned,
            $usersTouched,
        ));

        return self::SUCCESS;
    }
}
