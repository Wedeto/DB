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
 * @covers WASP\DB\Query\Operator
 */
class OperatorTest extends TestCase
{
    public function testOperator()
    {
        $a = new MockTestOperatorOperator('foo', 'field', 'value');
        
        $this->assertEquals('foo', $a->getOperator());
        
        $lhs = $a->getLHS();
        $rhs = $a->getRHS();

        $this->assertInstanceOf(FieldName::class, $lhs);
        $this->assertEquals('field', $lhs->getField());
        $this->assertInstanceOf(ConstantValue::class, $rhs);
        $this->assertEquals('value', $rhs->getValue());
    }

    public function testInvalidOperator()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid operator");
        $a = new MockTestOperatorOperator('baz', 'field', 'value');
        
    }
}

class MockTestOperatorOperator extends Operator
{
    protected static $valid_operators = ['foo', 'bar'];
}
