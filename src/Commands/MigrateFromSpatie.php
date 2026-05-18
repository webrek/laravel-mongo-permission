<?php

namespace Webrek\MongoPermission\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateFromSpatie extends Command
{
    protected $signature = 'permission:migrate-from-spatie
        {--connection=mysql : SQL connection name to read spatie tables from}
        {--user-model= : Mongo user model. Defaults to auth.providers.users.model.}
        {--sql-user-table=users : SQL table containing user records}
        {--match-by=email : Field used to match SQL users to Mongo users}
        {--skip-users : Migrate roles and permissions only, skip user assignments}
        {--force : Overwrite Mongo roles/permissions that already exist with the same (name, guard, team)}
        {--dry-run : Report what would happen without writing}';

    protected $description = 'Import roles, permissions and assignments from spatie/laravel-permission SQL tables into Mongo';

    /** @var array<string, string> SQL permission id → Mongo permission id */
    protected array $permissionMap = [];
    /** @var array<string, string> SQL role id → Mongo role id */
    protected array $roleMap = [];

    /** counters */
    protected int $permsCreated = 0;
    protected int $permsSkipped = 0;
    protected int $permsOverwritten = 0;
    protected int $rolesCreated = 0;
    protected int $rolesSkipped = 0;
    protected int $rolesOverwritten = 0;
    protected int $roleEdges = 0;
    protected int $userRoleAssignments = 0;
    protected int $userPermAssignments = 0;
    protected int $usersUnmapped = 0;

    public function handle(): int
    {
        $connection = $this->option('connection');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $sql = DB::connection($connection);
        } catch (\Throwable $e) {
            $this->error(sprintf('Cannot reach SQL connection "%s": %s', $connection, $e->getMessage()));
            return self::FAILURE;
        }

        $this->info(sprintf('Reading spatie tables from connection "%s"%s.', $connection, $dryRun ? ' (dry run)' : ''));

        $permClass = config('permission.models.permission');
        $roleClass = config('permission.models.role');

        // 1) Permissions
        $sqlPerms = $sql->table('permissions')->get();
        foreach ($sqlPerms as $sp) {
            $existing = $permClass::query()
                ->where('name', $sp->name)
                ->where('guard_name', $sp->guard_name ?? 'web')
                ->where('team_id', $sp->team_id ?? null)
                ->first();

            if ($existing) {
                if ($force && ! $dryRun) {
                    $existing->save();
                    $this->permsOverwritten++;
                } else {
                    $this->permsSkipped++;
                }
                $this->permissionMap[(string) $sp->id] = (string) $existing->getKey();
                continue;
            }

            if ($dryRun) {
                $this->permsCreated++;
                $this->permissionMap[(string) $sp->id] = 'pending-' . $sp->id;
                continue;
            }

            $perm = $permClass::create([
                'name' => $sp->name,
                'guard_name' => $sp->guard_name ?? 'web',
                'team_id' => $sp->team_id ?? null,
            ]);
            $this->permissionMap[(string) $sp->id] = (string) $perm->getKey();
            $this->permsCreated++;
        }

        // 2) Roles
        $sqlRoles = $sql->table('roles')->get();
        foreach ($sqlRoles as $sr) {
            $existing = $roleClass::query()
                ->where('name', $sr->name)
                ->where('guard_name', $sr->guard_name ?? 'web')
                ->where('team_id', $sr->team_id ?? null)
                ->first();

            if ($existing) {
                if ($force && ! $dryRun) {
                    $existing->save();
                    $this->rolesOverwritten++;
                } else {
                    $this->rolesSkipped++;
                }
                $this->roleMap[(string) $sr->id] = (string) $existing->getKey();
                continue;
            }

            if ($dryRun) {
                $this->rolesCreated++;
                $this->roleMap[(string) $sr->id] = 'pending-' . $sr->id;
                continue;
            }

            $role = $roleClass::create([
                'name' => $sr->name,
                'guard_name' => $sr->guard_name ?? 'web',
                'team_id' => $sr->team_id ?? null,
            ]);
            $this->roleMap[(string) $sr->id] = (string) $role->getKey();
            $this->rolesCreated++;
        }

        // 3) role_has_permissions → Role::permission_ids
        $roleHasPerms = $sql->table('role_has_permissions')->get();
        $byRole = [];
        foreach ($roleHasPerms as $row) {
            $mongoRoleId = $this->roleMap[(string) $row->role_id] ?? null;
            $mongoPermId = $this->permissionMap[(string) $row->permission_id] ?? null;
            if (! $mongoRoleId || ! $mongoPermId) {
                continue;
            }
            $byRole[$mongoRoleId][] = $mongoPermId;
            $this->roleEdges++;
        }

        if (! $dryRun) {
            foreach ($byRole as $mongoRoleId => $permIds) {
                $role = $roleClass::query()->where('_id', $mongoRoleId)->first();
                if (! $role) {
                    continue;
                }
                $current = array_map('strval', $role->permission_ids ?? []);
                $merged = array_values(array_unique(array_merge($current, $permIds)));
                $role->permission_ids = $merged;
                $role->save();
            }
        }

        // 4) User assignments
        if (! $this->option('skip-users')) {
            $this->migrateUserAssignments($sql, $dryRun);
        }

        // 5) Summary
        $this->line('');
        $this->info('Migration summary');
        $this->line(sprintf('  Permissions: %d created, %d skipped (already present), %d overwritten', $this->permsCreated, $this->permsSkipped, $this->permsOverwritten));
        $this->line(sprintf('  Roles:       %d created, %d skipped, %d overwritten', $this->rolesCreated, $this->rolesSkipped, $this->rolesOverwritten));
        $this->line(sprintf('  Role->permission edges resolved: %d', $this->roleEdges));
        if (! $this->option('skip-users')) {
            $this->line(sprintf('  User role assignments:       %d', $this->userRoleAssignments));
            $this->line(sprintf('  User permission assignments: %d', $this->userPermAssignments));
            if ($this->usersUnmapped > 0) {
                $this->warn(sprintf('  %d SQL user(s) could not be matched in Mongo by "%s"', $this->usersUnmapped, $this->option('match-by')));
            }
        }
        if ($dryRun) {
            $this->warn('Dry run — no documents were written.');
        }

        return self::SUCCESS;
    }

    protected function migrateUserAssignments($sql, bool $dryRun): void
    {
        $userClass = $this->option('user-model') ?: config('auth.providers.users.model');
        if (! $userClass || ! class_exists($userClass)) {
            $this->warn('No Mongo user model configured. Skipping user assignments.');
            return;
        }

        $userTable = $this->option('sql-user-table');
        $matchBy = $this->option('match-by');

        // Build SQL user_id → match-by value
        $sqlUserMatch = $sql->table($userTable)
            ->select(['id', $matchBy])
            ->get()
            ->keyBy('id')
            ->map(fn ($u) => $u->{$matchBy})
            ->all();

        // Resolve match values → Mongo users (one fetch)
        $matchValues = array_values(array_filter($sqlUserMatch));
        $mongoUsers = empty($matchValues)
            ? collect()
            : $userClass::query()->whereIn($matchBy, $matchValues)->get()->keyBy(fn ($u) => $u->{$matchBy});

        $sqlIdToMongoUser = [];
        $unmapped = [];
        foreach ($sqlUserMatch as $sqlId => $matchValue) {
            $mongoUser = $mongoUsers->get($matchValue);
            if ($mongoUser) {
                $sqlIdToMongoUser[(string) $sqlId] = $mongoUser;
            } else {
                $unmapped[(string) $sqlId] = $matchValue;
            }
        }

        $this->usersUnmapped = count($unmapped);

        // Group spatie assignments per user.
        $roleAssignments = [];
        foreach ($sql->table('model_has_roles')->get() as $row) {
            $sqlUserId = (string) ($row->model_id ?? $row->user_id ?? null);
            $mongoRoleId = $this->roleMap[(string) $row->role_id] ?? null;
            if (! $sqlUserId || ! $mongoRoleId) {
                continue;
            }
            $roleAssignments[$sqlUserId][] = [
                'role_id' => $mongoRoleId,
                'team_id' => $row->team_id ?? null,
                'expires_at' => null,
            ];
        }

        $permAssignments = [];
        foreach ($sql->table('model_has_permissions')->get() as $row) {
            $sqlUserId = (string) ($row->model_id ?? $row->user_id ?? null);
            $mongoPermId = $this->permissionMap[(string) $row->permission_id] ?? null;
            if (! $sqlUserId || ! $mongoPermId) {
                continue;
            }
            $permAssignments[$sqlUserId][] = [
                'permission_id' => $mongoPermId,
                'team_id' => $row->team_id ?? null,
                'expires_at' => null,
            ];
        }

        foreach ($sqlIdToMongoUser as $sqlId => $mongoUser) {
            $roles = $roleAssignments[$sqlId] ?? [];
            $perms = $permAssignments[$sqlId] ?? [];

            if (! $dryRun) {
                $existingRoles = $mongoUser->role_ids ?? [];
                $existingRoleIds = collect($existingRoles)->map(fn ($e) => (string) ($e['role_id'] ?? null))->all();
                $toAddRoles = array_filter($roles, fn ($r) => ! in_array($r['role_id'], $existingRoleIds, strict: true));

                $existingPerms = $mongoUser->permission_ids ?? [];
                $existingPermIds = collect($existingPerms)->map(fn ($e) => (string) ($e['permission_id'] ?? null))->all();
                $toAddPerms = array_filter($perms, fn ($p) => ! in_array($p['permission_id'], $existingPermIds, strict: true));

                if (! empty($toAddRoles)) {
                    $mongoUser->role_ids = array_merge($existingRoles, array_values($toAddRoles));
                }
                if (! empty($toAddPerms)) {
                    $mongoUser->permission_ids = array_merge($existingPerms, array_values($toAddPerms));
                }
                if (! empty($toAddRoles) || ! empty($toAddPerms)) {
                    $mongoUser->save();
                }
                $this->userRoleAssignments += count($toAddRoles);
                $this->userPermAssignments += count($toAddPerms);
            } else {
                $this->userRoleAssignments += count($roles);
                $this->userPermAssignments += count($perms);
            }
        }
    }
}
