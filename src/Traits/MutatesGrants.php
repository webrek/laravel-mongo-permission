<?php

namespace Webrek\MongoPermission\Traits;

use DateTimeInterface;
use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Support\AtomicArray;
use Webrek\MongoPermission\Support\Entry;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\Support\TeamScope;

trait MutatesGrants
{
    protected function mutateGrants(string $kind, array $models, string $operation, ?DateTimeInterface $expiresAt = null): self
    {
        $field = $kind.'_ids';
        $idKey = $kind.'_id';
        $ids = array_map(fn ($m) => (string) $m->getKey(), $models);
        $team = TeamScope::active();
        $expiry = Expiry::toBson($expiresAt);
        $ownedIds = app(PermissionRegistrar::class)->catalog(config('permission.models.'.$kind), $this->guardName())->map(fn ($m) => (string) $m->getKey())->all();
        [$before,$after] = AtomicArray::mutate($this, $field, function (array $entries) use ($ids, $idKey, $operation, $expiry, $team, $ownedIds) {
            $kept = [];
            $present = [];
            foreach ($entries as $entry) {
                $n = Entry::normalize($entry, $idKey);
                if (! TeamScope::owned($n['team_id']) || ! in_array($n['id'], $ownedIds, true)) {
                    $kept[] = $entry;

                    continue;
                }
                $target = in_array($n['id'], $ids, true);
                if (($operation === 'remove' && $target) || ($operation === 'sync' && ! $target)) {
                    continue;
                }
                if ($target && $operation !== 'remove') {
                    if (isset($present[$n['id']])) {
                        continue;
                    }
                    $present[$n['id']] = true;
                    // Existing live grants stay idempotent unless an explicit expiry is supplied.
                    $existingExpiry = Expiry::toBson(Expiry::toDateTime($n['expires_at']));
                    $replace = $expiry !== null ? (string) $existingExpiry !== (string) $expiry : Expiry::isExpired($n);
                    if ($replace) {
                        $entry = [$idKey => $n['id'], 'team_id' => $team, 'expires_at' => $expiry];
                    }
                }
                $kept[] = $entry;
            }
            if ($operation !== 'remove') {
                foreach ($ids as $id) {
                    if (! isset($present[$id])) {
                        $kept[] = [$idKey => $id, 'team_id' => $team, 'expires_at' => $expiry];
                        $present[$id] = true;
                    }
                }
            }

            return $kept;
        });
        if ($before == $after) {
            return $this;
        }
        app(PermissionRegistrar::class)->forgetUserCache((string) $this->getKey(), $team);
        $scoped = fn ($entries) => collect($entries)->map(fn ($e) => Entry::normalize($e, $idKey))->filter(fn ($n) => TeamScope::owned($n['team_id']))->keyBy('id');
        $old = $scoped($before);
        $new = $scoped($after);
        $class = config('permission.models.'.$kind);
        $changed = array_unique(array_merge($old->keys()->all(), $new->keys()->all()));
        $catalog = $class::query()->whereIn('_id', $changed)->get()->keyBy(fn ($m) => (string) $m->getKey());
        foreach ($changed as $id) {
            if (! isset($catalog[$id]) || $old->get($id) == $new->get($id)) {
                continue;
            }
            $event = 'Webrek\\MongoPermission\\Events\\'.ucfirst($kind).($new->has($id) ? 'Attached' : 'Detached');
            event(new $event($this, $catalog[$id], $team, $this->guardName()));
        }

        return $this;
    }

    protected function resolveGrantModels(array $entries, string $kind, ?string $guard = null): array
    {
        $guard ??= $this->guardName();
        $class = config('permission.models.'.$kind);
        $models = [];
        foreach ($entries as $entry) {
            $model = is_string($entry) ? $class::findByName($entry, $guard) : $entry;
            TeamScope::validate($model, $guard, TeamScope::active());
            $models[(string) $model->getKey()] = $model;
        }

        return array_values($models);
    }
}
