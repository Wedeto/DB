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
 * @covers WASP\DB\Query\WhereClause
 */
class WhereClauseTest extends TestCase
{
    public function testWhereFromString()
    {
        $a = new WhereClause("a > b");

        $operand = $a->getOperand();
        $this->assertInstanceOf(CustomSQL::class, $operand);
        $this->assertEquals('a > b', $operand->getSQL());
    }

    public function testWhereFromArray()
    {
        $a = new WhereClause(['foo' => 1, 'bar' => 'baz']);
        $operand = $a->getOperand();
        $this->assertInstanceOf(BooleanOperator::class, $operand);
        $this->assertEquals('AND', $operand->getOperator());
        
        $lhs = $operand->getLHS();
        $rhs = $operand->getRHS();

        $this->assertInstanceOf(ComparisonOperator::class, $lhs);
        $this->assertEquals('=', $lhs->getOperator());
        $this->assertInstanceOf(FieldName::class, $lhs->getLHS());
        $this->assertEquals('foo', $lhs->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $lhs->getRHS());
        $this->assertEquals(1, $lhs->getRHS()->getValue());


        $this->assertInstanceOf(ComparisonOperator::class, $rhs);
        $this->assertEquals('=', $rhs->getOperator());
        $this->assertInstanceOf(FieldName::class, $rhs->getLHS());
        $this->assertEquals('bar', $rhs->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $rhs->getRHS());
        $this->assertEquals('baz', $rhs->getRHS()->getValue());
    }

    public function testWhereFromExpression()
    {
        $expr = Builder::and(
            Builder::equals('foo', 1),
            Builder::equals('bar', 'baz')
        );
        
        $a = new WhereClause($expr);
        $operand = $a->getOperand();
        $this->assertEquals($expr, $operand);
    }

    public function testWhereWithInvalidOperand()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid operand");
        $a = new WhereClause(new \StdClass);
    }
}
