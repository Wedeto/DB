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
use WASP\DB\Driver\Driver;

class ConstantValueTest extends TestCase
{
    public function testConstruct()
    {
        $a = new ConstantValue(3);
        $this->assertEquals(3, $a->getValue());

        $a = new ConstantValue(3.5);
        $this->assertEquals(3.5, $a->getValue());

        $a = new ConstantValue("foo");
        $this->assertEquals("foo", $a->getValue());

        $dt = new \DateTime();
        $expected = $dt->format(\DateTime::ISO8601);
        $a = new ConstantValue($dt);
        $this->assertEquals($expected, $a->getValue());
    }

    public function testWithInvalidValue()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid data type for constant");
        $a = new ConstantValue(new \StdClass);
    }
    
    public function testUpdateInParameters()
    {
        $mock = $this->prophesize(Parameters::class);
        $mock->set('fookey', 'foo')->shouldBeCalled();
        $params = $mock->reveal();

        $const = new ConstantValue('foo');
        $this->assertEquals('foo', $const->getValue());

        $const->bind($params, 'fookey',  null);

        $mock->set('fookey', 'baz')->shouldBeCalled();
        $const->setValue('baz');

        $this->assertEquals('fookey', $const->getKey());
    }

    public function testUpdateInParametersWithFormatter()
    {
        $fmt = function ($val) { return 'foobarbaz'; };

        $mock = $this->prophesize(Parameters::class);
        $params = $mock->reveal();

        $const = new ConstantValue('foo');
        $this->assertEquals('foo', $const->getValue());

        $mock->set('fookey', 'foobarbaz')->shouldBeCalled();
        $const->bind($params, 'fookey',  $fmt);
    }

    public function testUpdateWithInvalidCallback()
    {
        $func = "funcname";
        while (function_exists($func))
            $func = "funcname" . random_int(1, 10000);

        $mock = $this->prophesize(Parameters::class);
        $params = $mock->reveal();

        $const = new ConstantValue('foo');
        $this->assertEquals('foo', $const->getValue());

        $this->expectException(\InvalidArgumentException::class);
        $const->bind($params, 'fookey',  $func);
    }
}
