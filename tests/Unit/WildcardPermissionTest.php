<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Exceptions\WildcardPermissionInvalidArgument;
use Webrek\MongoPermission\Tests\TestCase;
use Webrek\MongoPermission\WildcardPermission;

class WildcardPermissionTest extends TestCase
{
    public static function impliesMatrix(): array
    {
        return [
            // owned, checked, expected
            'exact match' => ['posts.edit', 'posts.edit', true],
            'tail wildcard matches one segment after' => ['posts.*', 'posts.edit', true],
            'tail wildcard matches multiple segments' => ['posts.*', 'posts.edit.own', true],
            'tail wildcard does not match bare prefix' => ['posts.*', 'posts', false],
            'star alone matches any non-empty name' => ['*', 'anything', true],
            'star alone matches segmented name' => ['*', 'a.b.c', true],
            'interior wildcard exact segment count' => ['*.read', 'posts.read', true],
            'interior wildcard rejects different segment count' => ['*.read', 'posts.users.read', false],
            'interior wildcard rejects mismatched final' => ['*.read', 'posts.write', false],
            'literal does not imply longer name' => ['posts', 'posts.edit', false],
            'literal does not imply shorter name' => ['posts.edit', 'posts', false],
        ];
    }

    /**
     * @dataProvider impliesMatrix
     */
    public function test_implies(string $owned, string $checked, bool $expected): void
    {
        $this->assertSame($expected, WildcardPermission::implies($owned, $checked));
    }

    public function test_implies_uses_configured_separator(): void
    {
        config()->set('permission.wildcard_separator', ':');

        $this->assertTrue(WildcardPermission::implies('posts:*', 'posts:edit'));
        $this->assertFalse(WildcardPermission::implies('posts:*', 'posts'));
    }

    public function test_implies_rejects_empty_owned(): void
    {
        $this->expectException(WildcardPermissionInvalidArgument::class);
        WildcardPermission::implies('', 'posts.edit');
    }

    public function test_implies_rejects_empty_checked(): void
    {
        $this->expectException(WildcardPermissionInvalidArgument::class);
        WildcardPermission::implies('posts.*', '');
    }
}
