<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

namespace Wedeto\DB\Query;

use PHPUnit\Framework\TestCase;

require_once "ProvideMockDb.php";
use Wedeto\DB\MockDB;

/**
 * @covers Wedeto\DB\Query\DuplicateKey
 */
class DupicateKeyTest extends TestCase
{
    public function testDuplicateKeys()
    {
        $field1 = new FieldName('foo');
        $a = new DuplicateKey($field1);
        $this->assertEquals([$field1], $a->getConflictingFields());
        $this->assertEquals([], $a->getUpdates());

        $update = new UpdateField('foo', 3);
        $update2 = new UpdateField('bar', 'baz');

        $expected = [$update, $update2];
        
        $field2 = new FieldName('test');
        $a = new DuplicateKey('test', $expected);
        $this->assertEquals([$field2], $a->getConflictingFields());
        $this->assertEquals($expected, $a->getUpdates());

        $a = new DuplicateKey([$field1, $field2], $expected);
        $this->assertEquals([$field1, $field2], $a->getConflictingFields());
    }

    public function testPostgresImplementation()
    {
        $db = new MockDB("PGSQL");
        $drv = $db->getDriver();
        $params = new Parameters($drv);

        $dupkey = new DuplicateKey("id", [new UpdateField("foo", "bar"), new UpdateField("test", "test2")]);

        $sql = $dupkey->toSQL($params, false);
        $this->assertEquals('ON CONFLICT ("id") DO UPDATE SET "foo" = :c0, "test" = :c1', $sql);
        $this->assertEquals('bar', $params->get('c0'));
        $this->assertEquals('test2', $params->get('c1'));
    }

    public function testMySQLImplementation()
    {
        $db = new MockDB("MySQL");
        $drv = $db->getDriver();
        $params = new Parameters($drv);

        $dupkey = new DuplicateKey("id", [new UpdateField("foo", "bar"), new UpdateField("test", "test2")]);

        $sql = $dupkey->toSQL($params, false);
        $this->assertEquals('ON DUPLICATE KEY UPDATE `foo` = :c0, `test` = :c1', $sql);
        $this->assertEquals('bar', $params->get('c0'));
        $this->assertEquals('test2', $params->get('c1'));
    }

    public function testOtherImplementation()
    {
        $mocker = $this->prophesize(\Wedeto\DB\Driver\Driver::class);
        $drv = $mocker->reveal();
        $params = new Parameters($drv);
        $dupkey = new DuplicateKey("id", [new UpdateField("foo", "bar"), new UpdateField("test", "test2")]);

        $this->expectException(\Wedeto\DB\Exception\ImplementationException::class);
        $this->expectExceptionMessage("On duplicate key not implemented for");
        $sql = $dupkey->toSQL($params, false);
    }
}
