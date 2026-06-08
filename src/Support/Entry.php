<?php

namespace Webrek\MongoPermission\Support;

/**
 * Normalises a role/permission assignment entry to a consistent shape,
 * accepting both the structured form written by this package
 * ({@code ['role_id' => ..., 'team_id' => ..., 'expires_at' => ...]}) and the
 * legacy "flat" form where the entry is just the id as a string
 * ({@code "6796f9...d70"}). This keeps data written by older tooling (e.g.
 * Maklad) working alongside data written by this package.
 */
class Entry
{
    /**
     * @param  string  $idKey  'role_id' or 'permission_id'
     * @return array{id: string|null, team_id: string|null, expires_at: mixed}
     */
    public static function normalize(mixed $entry, string $idKey): array
    {
        if (is_array($entry)) {
            return [
                'id' => isset($entry[$idKey]) ? (string) $entry[$idKey] : null,
                'team_id' => $entry['team_id'] ?? null,
                'expires_at' => $entry['expires_at'] ?? null,
            ];
        }

        // Legacy flat form: the entry is the id itself.
        return [
            'id' => (string) $entry,
            'team_id' => null,
            'expires_at' => null,
        ];
    }

    /**
     * The list of ids from a set of entries (either form), dropping empties.
     *
     * @param  iterable<mixed>  $entries
     * @return list<string>
     */
    public static function ids(iterable $entries, string $idKey): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $id = self::normalize($entry, $idKey)['id'];

            if ($id !== null && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
