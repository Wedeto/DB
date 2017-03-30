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
 * @covers WASP\DB\Query\Parameters
 */
class ParameterTest extends TestCase
{
    public function testParameters()
    {
        $p = new Parameters();
        $key = $p->assign('foo');

        $this->assertEquals('foo', $p->get($key));
        $p->set($key, 'bar');
        $this->assertEquals('bar', $p->get($key));

        $this->assertEquals([$key => 'bar'], $p->getParameters());
        $p->reset();
        $this->assertEquals([], $p->getParameters());
    }

    public function testGetInvalidKey()
    {
        $p = new Parameters();
        $this->expectException(\OutOfRangeException::class);
        $this->expectExceptionMessage("Invalid key: foo");
        $p->get('foo');
    }

    public function testTableRegistration()
    {
        $a = new Parameters();

        $a->registerTable('foo', 'f');
        $actual = $a->resolveTable('foo');
        $expected = ['foo', null];
        $this->assertEquals($expected, $actual);

        $actual = $a->resolveTable('f');
        $expected = ['foo', 'f'];
        $this->assertEquals($expected, $actual);

        $this->assertEquals(['f' => true], $a->getTable('foo'));

        // Should be a no-op
        $a->registerTable(null, 'f2');

        $a->registerTable('foo', 'f3');
        $this->assertEquals(['f' => true, 'f3' => true], $a->getTable('foo'));


        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown source table f2");
        $actual = $a->resolveTable('f2');
    }

    public function testDuplicateTable()
    {
        $a = new Parameters();

        $a->registerTable('foo', null);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate table without an alias");
        $a->registerTable('foo', null);
    }

    public function testDuplicateTableWithDuplicateAlias()
    {
        $a = new Parameters();

        $a->registerTable('foo', 'f');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate alias f for table foo");
        $a->registerTable('foo', 'f');
    }

    public function testDuplicateTableWhereFirstInstanceHasNoAlias()
    {
        $a = new Parameters();

        $a->registerTable('foo', null);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("All instances of a table reference must be aliased if used more than once");
        $a->registerTable('foo', 'f');
    }

    public function testDuplicateAliasForDifferentTables()
    {
        $a = new Parameters();

        $a->registerTable('foo', 'fb');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate alias fb for table bar - also referring to foo");
        $a->registerTable('bar', 'fb');
    }

    public function testDuplicateTableResolution()
    {
        $a = new Parameters();

        $a->registerTable('foo', 'f1');
        $a->registerTable('foo', 'f2');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Multiple references to foo, use the appropriate alias");
        $resolve = $a->resolveTable('foo');
    }

    public function testResolveNoTable()
    {
        $a = new Parameters();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No table identifier provided");
        $a->resolveTable(null);
    }

    public function testGetDefaultTable()
    {
        $a = new Parameters;

        $this->assertNull($a->getDefaultTable());

        $a->registerTable('foo', 'f');
        $this->assertNull($a->getDefaultTable());

        $a->registerTable('bar', 'b');
        $def = $a->getDefaultTable();
        $this->assertInstanceOf(TableClause::class, $def);
        $this->assertEquals('f', $def->getTable());
    }

    public function testGetDefaultTableWithoutAlias()
    {
        $a = new Parameters;

        $this->assertNull($a->getDefaultTable());

        $a->registerTable('foo', null);
        $this->assertNull($a->getDefaultTable());

        $a->registerTable('bar', 'b');
        $def = $a->getDefaultTable();
        $this->assertInstanceOf(TableClause::class, $def);
        $this->assertEquals('foo', $def->getTable());
    }
}
