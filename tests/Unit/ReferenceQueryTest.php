<?php

namespace Webrek\MongoPermission\Tests\Unit;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Webrek\MongoPermission\Support\Entry;

class ReferenceQueryTest extends TestCase
{
    public function test_native_and_uppercase_objectids_and_arbitrary_text_references(): void
    {
        $hex = 'ABCDEF0123456789ABCDEF01';
        $values = Entry::queryIds([$hex]);
        $this->assertSame($hex, $values[0]);
        $this->assertEquals(new ObjectId($hex), $values[1]);
        $this->assertCount(2, $values);
        $this->assertEquals([strtolower($hex), new ObjectId($hex)], Entry::queryIds([new ObjectId($hex)]));
        foreach (['prefix-'.$hex, $hex.'-suffix', 'short', ''] as $text) {
            $this->assertSame([$text], Entry::queryIds([$text]));
        }
        $this->assertSame([], Entry::queryIds([]));
    }
}
