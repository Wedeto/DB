<?php
/*
This is part of WASP, the Web Application Software Platform.
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

namespace WASP\DB\Query;

use PHPUnit\Framework\TestCase;

/**
 * @covers WASP\DB\Query\Insert
 */
class InsertTest extends TestCase
{
    public function testInsert()
    {
        $table = "test_table";
        $record = array('foo' => 'bar', 'baz' => 5);

        $i = new Insert($table, $record);

        $fields = $i->getFields();
        $this->assertEquals(2, count($fields));
        $first = $fields[0];
        $second = $fields[1];
        $this->assertEquals("foo", $first->getField());
        $this->assertEquals("baz", $second->getField());

        $values = $i->getValues();
        $this->assertEquals(2, count($values));
        $first = $values[0];
        $second = $values[1];
        $this->assertInstanceOf(ConstantValue::class, $first);
        $this->assertEquals('bar', $first->getValue());
        $this->assertInstanceOf(ConstantValue::class, $second);
        $this->assertEquals(5, $second->getValue());
        
        $t = $i->getTable();
        $this->assertInstanceOf(TableClause::class, $t);
        $this->assertEquals($table, $t->getTable());
    }

    public function testInsertId()
    {
        $table = "test_table";
        $record = array('foo' => 'bar');

        $i = new Insert($table, $record, ['id' => 'id']);
        $expected = 5;
        $i->setInsertId($expected);
        $this->assertEquals(['id' => $expected], $i->getInsertId());
    }

    public function testInsertWithOnDuplicate()
    {
        $table = "test_table";
        $record = array('foo' => 'bar', 'baz' => 3);

        $i = new Insert($table, $record);
        $i->updateOnDuplicateKey("foo");

        $dk = $i->getOnDuplicate();
        $this->assertInstanceOf(DuplicateKey::class, $dk);

        $f = $dk->getConflictingFields();
        $this->assertEquals(1, count($f));
        $first = reset($f);
        $this->assertEquals('foo', $first->getField());

        $updates = $dk->getUpdates();
        $this->assertEquals(1, count($updates));
        $update = reset($updates);
        $this->assertInstanceOf(UpdateField::class, $update);
        $f = $update->getField();
        $this->assertEquals("baz", $f->getField());
        $v = $update->getValue();
        $this->assertInstanceOf(ConstantValue::class, $v);
        $this->assertEquals(3, $v->getValue());
    }

    public function testInsertUsingDAO()
    {
        $mock = $this->prophesize(\WASP\DB\DAO::class);
        $mock->getRecord()->willReturn(['foo' => 'bar', 'baz' => 3]);
        $dao = $mock->reveal();

        $table = "test_table";

        $i = new Insert($table, $dao);

        $fields = $i->getFields();
        $this->assertEquals(2, count($fields));
        $this->assertInstanceOf(FieldName::class, $fields[0]);
        $this->assertEquals("foo", $fields[0]->getField());
        $this->assertInstanceOf(FieldName::class, $fields[1]);
        $this->assertEquals("baz", $fields[1]->getField());

        $values = $i->getValues();
        $this->assertEquals(2, count($values));
        $this->assertInstanceOf(ConstantValue::class, $values[0]);
        $this->assertEquals("bar", $values[0]->getValue());
        $this->assertInstanceOf(ConstantValue::class, $values[1]);
        $this->assertEquals(3, $values[1]->getValue());
    }

    public function testInsertWithIDField()
    {
        $table = "test_table";
        $record = ['foo' => 'bar', 'baz' => 3];
        
        $i = new Insert($table, $record, ["id"]);

        $this->assertEquals(['id'], $i->getPrimaryKey());
    }
}
