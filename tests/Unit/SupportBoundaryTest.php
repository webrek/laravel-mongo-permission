<?php

namespace Webrek\MongoPermission\Tests\Unit;

use DateTimeImmutable;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Webrek\MongoPermission\Support\Entry;
use Webrek\MongoPermission\Support\Expiry;
use Webrek\MongoPermission\Tests\TestCase;
use Webrek\MongoPermission\WildcardPermission;

class SupportBoundaryTest extends TestCase
{
    public function test_expiry_representations_and_exact_boundary(): void
    {
        $this->travelTo(new DateTimeImmutable('2030-01-01T12:00:00Z'));
        $date = new DateTimeImmutable('2030-01-01T12:01:00Z');
        $bson = Expiry::toBson($date);
        $this->assertSame((string) ($date->getTimestamp() * 1000), (string) $bson);
        $this->assertNull(Expiry::toBson(null));
        foreach ([$date, $bson, $date->getTimestamp(), '2030-01-01T13:01:00+01:00'] as $value) {
            $this->assertSame($date->getTimestamp(), Expiry::toDateTime($value)->getTimestamp());
            $entry = ['expires_at' => $value];
            $this->assertFalse(Expiry::isExpired($entry));
            $this->assertTrue(Expiry::notExpired($entry));
            $this->assertTrue(Expiry::isExpired($entry, $date));
            $this->assertTrue(Expiry::isExpired($entry, $date->modify('+1 second')));
            $this->assertFalse(Expiry::isExpired($entry, $date->modify('-1 second')));
        }
        $this->assertFalse(Expiry::isExpired([]));
        $this->assertNull(Expiry::toDateTime(null));
        $this->assertNull(Expiry::toDateTime([]));
    }

    public function test_legacy_and_structured_entry_ids_keep_identity_and_metadata(): void
    {
        $id = new ObjectId;
        $expiry = new UTCDateTime(1000);
        $this->assertSame(['id' => (string) $id, 'team_id' => '0', 'expires_at' => $expiry], Entry::normalize(['role_id' => $id, 'team_id' => '0', 'expires_at' => $expiry], 'role_id'));
        $this->assertSame(['id' => '12', 'team_id' => null, 'expires_at' => null], Entry::normalize(12, 'role_id'));
        $this->assertSame(['id' => null, 'team_id' => null, 'expires_at' => null], Entry::normalize([], 'role_id'));
        $this->assertSame(['12', (string) $id, '0'], Entry::ids([[], '', 12, ['role_id' => $id], ['role_id' => 0]], 'role_id'));
    }

    public function test_wildcard_boundaries_and_custom_separator(): void
    {
        foreach ([
            ['*', 'anything', true], ['posts.*', 'posts', false],
            ['posts.*', 'posts.edit', true], ['posts.*', 'posts.edit.own', true],
            ['posts.*', 'users.edit', false], ['*.edit', 'posts.edit', true],
            ['*.edit', 'posts.edit.own', false], ['*.edit', 'posts.read', false],
            ['a.*.c', 'a.b.c', true], ['a.*.c', 'a.b.d', false],
            ['a.*.c', 'b.b.c', false], ['a.*.*', 'a.b.c.d', true],
            ['a.*.*', 'a.b', false], ['a.b', 'a.b', true], ['a.b', 'a', false],
            ['a.b', 'a.c', false], ['a.b', 'c.b', false],
        ] as [$owned, $checked, $expected]) {
            $this->assertSame($expected, WildcardPermission::implies($owned, $checked), "$owned -> $checked");
        }
        config(['permission.wildcard_separator' => ':']);
        $this->assertTrue(WildcardPermission::implies('posts:*', 'posts:edit:own'));
        $this->assertFalse(WildcardPermission::implies('posts:*', 'posts.edit'));
    }
}
