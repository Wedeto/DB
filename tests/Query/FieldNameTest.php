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

/**
 * @covers Wedeto\DB\Query\FieldName
 */
class FieldNameTest extends TestCase
{
    public function testFieldNoTable()
    {
        $a = new FieldName('foo');

        $this->assertEquals('foo', $a->getField());
    }

    public function testFieldTableAsString()
    {
        $a = new FieldName('bar', 'foo');

        $this->assertEquals('bar', $a->getField());

        $tab = $a->getTable();
        $this->assertInstanceOf(TableClause::class, $tab);
        $this->assertEquals("foo", $tab->getTable());
    }

    public function testFieldTableAsClause()
    {
        $tab = new TableClause('foo');
        $a = new FieldName('bar', $tab);

        $this->assertEquals('bar', $a->getField());

        $tab2 = $a->getTable();
        $this->assertInstanceOf(TableClause::class, $tab);
        $this->identicalTo($tab, $tab2);
        $this->assertEquals("foo", $tab2->getTable());
    }

    public function testToSQL()
    {
        $db = new MockDB();
        $drv = $db->getDriver();
        $params = new Parameters($drv);

        $table = new SourceTableClause("footable");
        // Register the table before attempting to access it
        $table->toSQL($params, false);

        $val = new FieldName('foocolumn');
        $val3 = new FieldName('barcolumn', $table);
        $val2 = new FieldName('foocolumn', 'footable');

        $this->assertEquals('"foocolumn"', $val->toSQL($params, false));
        $this->assertEquals('"footable"."foocolumn"', $val2->toSQL($params, false));
        $this->assertEquals('"footable"."barcolumn"', $val3->toSQL($params, false));

        $table2 = new SourceTableClause("footable2", "f2");
        // Register the table before attempting to access it
        $table2->toSQL($params, false);

        $val4 = new FieldName('foobarcolumn', $table2);
        $this->assertEquals('"f2"."foobarcolumn"', $val4->toSQL($params, false));
    }
}
