<?php

namespace Webrek\MongoPermission\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;

class Expiry
{
    public static function toBson(?DateTimeInterface $expiresAt): ?UTCDateTime
    {
        if ($expiresAt === null) {
            return null;
        }
        return new UTCDateTime((int) ($expiresAt->getTimestamp() * 1000));
    }

    public static function toDateTime(mixed $raw): ?DateTimeInterface
    {
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof UTCDateTime) {
            return $raw->toDateTime();
        }
        if ($raw instanceof DateTimeInterface) {
            return $raw;
        }
        if (is_int($raw)) {
            return (new DateTimeImmutable())->setTimestamp($raw);
        }
        if (is_string($raw)) {
            return Carbon::parse($raw)->toDateTimeImmutable();
        }
        return null;
    }

    public static function isExpired(array $entry, ?DateTimeInterface $now = null): bool
    {
        $exp = self::toDateTime($entry['expires_at'] ?? null);
        if ($exp === null) {
            return false;
        }
        $reference = $now ?? Carbon::now();
        return $exp <= $reference;
    }

    public static function notExpired(array $entry): bool
    {
        return ! self::isExpired($entry);
    }
}
