<?php

namespace Webrek\MongoPermission;

use Webrek\MongoPermission\Exceptions\WildcardPermissionInvalidArgument;

class WildcardPermission
{
    public static function implies(string $owned, string $checked): bool
    {
        if ($owned === '') {
            throw WildcardPermissionInvalidArgument::empty('owned');
        }
        if ($checked === '') {
            throw WildcardPermissionInvalidArgument::empty('checked');
        }

        $separator = (string) config('permission.wildcard_separator', '.');

        // Sole '*' matches any non-empty name
        if ($owned === '*') {
            return true;
        }

        $ownedSegments = explode($separator, $owned);
        $checkedSegments = explode($separator, $checked);

        $lastIndex = count($ownedSegments) - 1;
        $tailWildcard = $ownedSegments[$lastIndex] === '*';

        if ($tailWildcard) {
            // Trailing '*' is greedy: requires at least one more segment after the prefix.
            if (count($checkedSegments) <= $lastIndex) {
                return false;
            }
            for ($i = 0; $i < $lastIndex; $i++) {
                if ($ownedSegments[$i] !== '*' && $ownedSegments[$i] !== $checkedSegments[$i]) {
                    return false;
                }
            }
            return true;
        }

        // No trailing wildcard: segment counts must match exactly.
        if (count($ownedSegments) !== count($checkedSegments)) {
            return false;
        }
        for ($i = 0; $i <= $lastIndex; $i++) {
            if ($ownedSegments[$i] !== '*' && $ownedSegments[$i] !== $checkedSegments[$i]) {
                return false;
            }
        }
        return true;
    }
}
