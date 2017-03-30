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
 * @covers WASP\DB\Query\Delete
 */
class DeleteTest extends TestCase
{
    public function testDeleteQueryWithStrings()
    {
        $table = "test_table";
        $where = "foo = bar";

        $d = new Delete($table, $where);

        $t = $d->getTable();
        $this->assertInstanceOf(TableClause::class, $t);
        $this->assertEquals($table, $t->getTable());

        $w = $d->getWhere();
        $this->assertInstanceOf(WhereClause::class, $w);

        $op = $w->getOperand();
        $this->assertInstanceOf(CustomSQL::class, $op);
        $this->assertEquals($where, $op->getSQL());
    }

    public function testDeleteQueryWithObjects()
    {
        $table = new TableClause("test_table");
        $op = new ComparisonOperator("=", "foo", new FieldName("bar"));

        $where = new WhereClause($op);

        $d = new Delete($table, $where);
        $t = $d->getTable();
        $this->assertInstanceOf(TableClause::class, $t);
        $this->assertEquals("test_table", $t->getTable());

        $w = $d->getWhere();
        $this->assertInstanceOf(WhereClause::class, $w);

        $op = $w->getOperand();
        $this->assertInstanceOf(ComparisonOperator::class, $op);
        $this->assertEquals("=", $op->getOperator());
    }

    public function testInvalidTable()
    {
        $table = new \StdClass;
        $where = "foo = bar";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid table");
        $d = new Delete($table, $where);
    }
}
