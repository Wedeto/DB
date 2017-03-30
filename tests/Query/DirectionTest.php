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
 * @covers WASP\DB\Query\Direction
 */
class DirectionTest extends TestCase
{
    public function testDirection()
    {
        $a = new Direction('ASC', 'foo');
        $this->assertEquals('ASC', $a->getDirection());
        $this->assertInstanceOf(FieldName::class, $a->getOperand());

        $c = new ConstantValue('foobar');
        $a = new Direction('ASC', $c);
        $this->assertEquals('ASC', $a->getDirection());
        $this->assertInstanceOf(ConstantValue::class, $a->getOperand());
        $this->identicalTo($c, $a->getOperand());

        $valid = array('ASC', 'ASC NULLS FIRST', 'ASC NULLS LAST', 'DESC NULLS FIRST', 'DESC NULLS LAST');
        foreach ($valid as $dir)
        {
            $a = new Direction($dir, 'foo');
            $this->assertEquals($dir, $a->getDirection());
            $this->assertInstanceOf(FieldName::class, $a->getOperand());
        }
    }

    public function testInvalidDirection()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid direction');
        $a = new Direction("FOOBAR", 'foo');
    }
}
