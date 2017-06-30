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

class ConstantArrayTest extends TestCase
{
    public function testConstruct()
    {
        $a = new ConstantArray([]);

        $expected = [];
        $actual = $a->getValue();
        $this->assertEquals($expected, $actual);

        $a = new ConstantArray(1, 2, 3, 4, 5);
        $expected = [1, 2, 3, 4, 5];
        $actual = $a->getValue();
        $this->assertEquals($expected, $actual);

        $a = new ConstantArray([1, 2, 3, 4, 5]);
        $expected = [1, 2, 3, 4, 5];
        $actual = $a->getValue();
        $this->assertEquals($expected, $actual);

        $a = new ConstantArray([1, 2, 3, 4, 5], [6, 7, 8, 9]);
        $expected = [1, 2, 3, 4, 5, 6, 7, 8, 9];
        $actual = $a->getValue();
        $this->assertEquals($expected, $actual);
    }

    public function testInvalidValue()
    {
        $a = new ConstantArray([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot assign non-array");

        $a->setValue(null);
    }

    public function testInvalidValues()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessagE("Not a scalar");
        $a = new ConstantArray(new \StdClass, new \StdClass);
    }

    public function testArrayWithAutoUpdate()
    {
        $func = function ($value) { return implode('-', $value); };

        $mock = $this->prophesize(Parameters::class);
        $params = $mock->reveal();

        $a = new ConstantArray([]);
        $mock->set('fookey', '', \PDO::PARAM_STR)->shouldBeCalled();
        $a->bind($params, 'fookey', $func);

        $mock->set('fookey', '1-2-3-4', \PDO::PARAM_STR)->shouldBeCalled();
        $a->setValue([1, 2, 3, 4]);
    }

    public function testToSQL()
    {
        $db = new MockDB();
        $drv = $db->getDriver();
        $params = new Parameters($drv);

        $val = new ConstantArray([1, 3, 5, 7]);
        $sql = $val->toSQL($params, false);
        $this->assertEquals(':c0', $sql);
        $this->assertEquals('{1,3,5,7}', $params->get('c0'));

        $val = new ConstantArray([1, "foo", 5, 7]);
        $sql = $val->toSQL($params, false);
        $this->assertEquals(':c1', $sql);
        $this->assertEquals('{1,"foo",5,7}', $params->get('c1'));

        $val = new ConstantArray([1, 'fo""o', 5, 7]);
        $sql = $val->toSQL($params, false);
        $this->assertEquals(':c2', $sql);
        $this->assertEquals('{1,"fo\\"\\"o",5,7}', $params->get('c2'));

        // Repetition should give the same results
        $sql = $val->toSQL($params, false);
        $this->assertEquals(':c2', $sql);
        $this->assertEquals('{1,"fo\\"\\"o",5,7}', $params->get('c2'));

        // New parameters should reset
        $params = new Parameters($drv);
        $sql = $val->toSQL($params, false);
        $this->assertEquals(':c0', $sql);
        $this->assertEquals('{1,"fo\\"\\"o",5,7}', $params->get('c0'));
    }
}
