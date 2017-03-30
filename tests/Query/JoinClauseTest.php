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
 * @covers WASP\DB\Query\JoinClause
 */
class JoinClauseTest extends TestCase
{
    public function testJoinClause()
    {
        $table1 = new TableClause("table1");
        $table2 = new TableClause("table2");

        $field1 = new FieldName("key1", $table1);
        $field2 = new FieldName("key2", $table2);

        $expr = new ComparisonOperator("=", $field1, $field2);

        $a = new JoinClause("LEFT", $table2, $expr);
        $this->assertEquals('LEFT', $a->getType());
        $this->identicalTo($expr, $a->getCondition());
        $this->identicalTo($table2, $a->getTable());

        $a = new JoinClause("LEFT", "table2", $expr);
        $this->assertEquals('LEFT', $a->getType());
        $this->identicalTo($expr, $a->getCondition());
        $this->assertEquals($table2->getTable(), $a->getTable()->getTable());

        $a = new JoinClause("LEFT", new SourceTableClause("table2"), $expr);
        $this->assertEquals('LEFT', $a->getType());
        $this->identicalTo($expr, $a->getCondition());
        $this->assertEquals($table2->getTable(), $a->getTable()->getTable());
    }

    public function testJoinInvalidTable()
    {
        $table1 = new TableClause("table1");
        $table2 = new TableClause("table2");

        $field1 = new FieldName("key1", $table1);
        $field2 = new FieldName("key2", $table2);

        $expr = new ComparisonOperator("=", $field1, $field2);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Invalid table type");
        $a = new JoinClause("LEFT", new \StdClass, $expr);
    }

    public function testJoinAcceptsAllTypes()
    {
        $table1 = new TableClause("table1");
        $table2 = new TableClause("table2");

        $field1 = new FieldName("key1", $table1);
        $field2 = new FieldName("key2", $table2);

        $expr = new ComparisonOperator("=", $field1, $field2);

        $types = array("LEFT", "RIGHT", "FULL", "INNER", "CROSS");
        foreach ($types as $type)
        {
            $a = new JoinClause($type, $table2, $expr);
            $this->assertEquals($type, $a->getType());
        }
    }

    public function testJoinWithInvalidJoinType()
    {
        $table1 = new TableClause("table1");
        $table2 = new TableClause("table2");

        $field1 = new FieldName("key1", $table1);
        $field2 = new FieldName("key2", $table2);

        $expr = new ComparisonOperator("=", $field1, $field2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid join type");
        $a = new JoinClause("FOOJOIN", $table2, $expr);
    }
}
