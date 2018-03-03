<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017-2018, Egbert van der Wal

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

use Wedeto\DB\Exception\OutOfRangeException;
use Wedeto\DB\Exception\QueryException;
use Wedeto\DB\Exception\ConfigurationException;
use Wedeto\DB\Exception\ImplementationException;

require_once "ProvideMockDb.php";
use Wedeto\DB\MockDB;

/**
 * @covers Wedeto\DB\Query\Parameters
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
        $this->expectException(OutOfRangeException::class);
        $this->expectExceptionMessage("Invalid key: foo");
        $p->get('foo');
    }

    public function testGetParameterTypeInvalidKey()
    {
        $p = new Parameters();
        $this->expectException(OutOfRangeException::class);
        $this->expectExceptionMessage("Invalid key: foo");
        $p->getParameterType('foo');
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


        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Unknown source table f2");
        $actual = $a->resolveTable('f2');
    }

    public function testDuplicateTable()
    {
        $a = new Parameters();

        $a->registerTable('foo', null);
        
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Duplicate table without an alias");
        $a->registerTable('foo', null);
    }

    public function testDuplicateTableWithDuplicateAlias()
    {
        $a = new Parameters();

        $a->registerTable('foo', 'f');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Duplicate alias \"f\" for table \"foo\"");
        $a->registerTable('foo', 'f');
    }

    public function testDuplicateTableWhereFirstInstanceHasNoAlias()
    {
        $a = new Parameters();

        $a->registerTable('foo', null);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("All instances of a table reference must be aliased if used more than once");
        $a->registerTable('foo', 'f');
    }

    public function testDuplicateAliasForDifferentTables()
    {
        $a = new Parameters();

        $a->registerTable('foo', 'fb');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Duplicate alias \"fb\" for table \"bar\" - also referring to \"foo\"");
        $a->registerTable('bar', 'fb');
    }

    public function testDuplicateTableResolution()
    {
        $a = new Parameters();

        $a->registerTable('foo', 'f1');
        $a->registerTable('foo', 'f2');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Multiple references to foo, use the appropriate alias");
        $resolve = $a->resolveTable('foo');
    }

    public function testResolveNoTable()
    {
        $a = new Parameters();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("No table identifier provided");
        $a->resolveTable('');
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

    public function testIterator()
    {
        $a = new Parameters;

        $str = fopen('php://memory', 'rw');

        $a->set('foo', 'bar');
        $a->set('foo2', 3, \PDO::PARAM_INT);
        $a->set('foo3', $str, \PDO::PARAM_LOB);

        $keys = ['foo', 'foo2', 'foo3'];
        $vals = ['bar', 3, $str];
        $types = [\PDO::PARAM_STR, \PDO::PARAM_INT, \PDO::PARAM_LOB];

        foreach ($a as $k => $v)
        {
            $this->assertEquals(array_shift($keys), $k);
            $this->assertEquals(array_shift($vals), $v);
            $this->assertEquals(array_shift($types), $a->parameterType());
        }

        $this->assertEmpty($keys);
    }

    public function testSubScopes()
    {
        $db = new MockDB();
        $drv = $db->getDriver();
        $a = new Parameters($drv);

        $this->assertSame($drv, $a->getDriver());

        $a->set('foo', 'bar');

        $scope1 = $a->getSubScope(null);
        $this->assertEquals(1, $scope1->getScopeID());

        $scope2 = $a->getSubScope(null);
        $this->assertEquals(2, $scope2->getScopeID());

        $scope_test = $a->getSubScope(1);
        $this->assertEquals(1, $scope_test->getScopeID());
        $this->assertSame($scope1, $scope_test);

        $scope1->set('foobar', 'yes');
        $this->assertEquals($scope1->get('foobar'), $scope2->get('foobar'));

        $a->registerTable('test_table', 'test_alias');
        $this->assertEquals(['test_table', null], $a->resolveTable('test_table'));
        $this->assertEquals(['test_table', 'test_alias'], $a->resolveTable('test_alias'));

        $this->assertEquals(['test_table', null], $scope1->resolveTable('test_table'));
        $this->assertEquals(['test_table', null], $scope2->resolveTable('test_table'));

        $this->assertEquals('test_table', $a->resolveAlias('test_alias'));
        $this->assertEquals('test_table', $scope1->resolveAlias('test_alias'));
        $this->assertEquals('test_table', $scope2->resolveAlias('test_alias'));

        $this->assertNull($a->resolveAlias('test_alias2'));
        $this->assertNull($scope1->resolveAlias('test_alias2'));
        $this->assertNull($scope2->resolveAlias('test_alias2'));

        $this->assertEquals(['test_table', 'test_alias'], $scope1->resolveTable('test_alias'));
        $this->assertEquals(['test_table', 'test_alias'], $scope2->resolveTable('test_alias'));

        $scope1->registerTable('test_table', '');
        $this->assertEquals(['test_table', null], $scope1->resolveTable('test_table'));
        $this->assertEquals(['test_table', null], $scope2->resolveTable('test_table'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("was already bound to \"test_table\" in parent scope");
        $scope2->registerTable('test_table', 'test_alias');
    }

    public function testGetDriverWithNoDriverAvailable()
    {
        $a = new Parameters;
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("No database driver provided to format query");
        $a->getDriver();
    }

    public function testGetInvalidSubScope()
    {
        $a = new Parameters;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid scope number: 42");
        $a->getsubScope(42);
    }

    public function testGenerateAlias()
    {
        $a = new Parameters;

        $f = new FieldName("col", "tab");

        $alias = $a->generateAlias($f);
        $this->assertEquals("tab_col", $alias);

        $alias = $a->generateAlias($f);
        $this->assertEquals("tab_col2", $alias);

        $f = new Fieldname("testcol");
        $alias = $a->generateAlias($f);

        $f = new SQLFunction("COUNT", "*");
        $alias = $a->generateAlias($f);
        $this->assertEquals("count", $alias);

        $f = new ComparisonOperator("=", "foo", "bar");
        $this->expectException(ImplementationException::class);
        $this->expectExceptionMessage("No alias generation implemented for: " . ComparisonOperator::class);
        $a->generateAlias($f);
    }

    public function testBindParameters()
    {
        $a = new Parameters;
        
        $a
            ->set('foo', 'bar')
            ->set('baz', 3);


        $mocker = $this->prophesize(\PDOStatement::class);
        $st = $mocker->reveal();

        $mocker->bindParam('foo', 'bar', \PDO::PARAM_STR)->shouldBeCalledTimes(1);
        $mocker->bindParam('baz', 3, \PDO::PARAM_STR)->shouldBeCalledTimes(1);
        $a->bindParameters($st);
    }
}
