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
 * @covers WASP\DB\Query\OrderClause
 */
class OrderTest extends TestCase
{
    public function testOrder()
    {
        $a = new OrderClause("TEST ASC");

        $clauses = $a->getClauses();
        $this->assertEquals(1, count($clauses));
        $this->assertEquals("TEST ASC", $clauses[0]->getOperand()->getField());

        $dir = new Direction("ASC", "foo");
        $a = new OrderClause($dir);

        $clauses = $a->getClauses();
        $this->assertEquals(1, count($clauses));
        $this->identicalTo($dir, $clauses[0]);
    }

    public function testOrderWithInvalidArgument()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid order");
        $a = new OrderClause(new \StdClass);
    }

    public function testOrderWithAddClause()
    {
        $a = new OrderClause;
        $a->addClause("TEST ASC");

        $clauses = $a->getClauses();
        $this->assertEquals(1, count($clauses));
        $this->assertEquals("TEST ASC", $clauses[0]->getSQL());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No clause provided");
        $a->addClause(new \StdClass);
    }

    public function testOrderFromArray()
    {
        $a = new OrderClause(['foo' => 'ASC', 'bar' => 'DESC']);

        $clauses = $a->getClauses();
        $this->assertEquals(2, count($clauses));

        $this->assertEquals('foo', $clauses[0]->getOperand()->getField());
        $this->assertEquals('ASC', $clauses[0]->getDirection());

        $this->assertEquals('bar', $clauses[1]->getOperand()->getField());
        $this->assertEquals('DESC', $clauses[1]->getDirection());

        $a = new OrderClause(['foo', 'bar']);

        $clauses = $a->getClauses();
        $this->assertEquals(2, count($clauses));

        $this->assertEquals('foo', $clauses[0]->getOperand()->getField());
        $this->assertEquals('ASC', $clauses[0]->getDirection());

        $this->assertEquals('bar', $clauses[1]->getOperand()->getField());
        $this->assertEquals('ASC', $clauses[1]->getDirection());
    }
}
