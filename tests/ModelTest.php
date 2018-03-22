<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2018, Egbert van der Wal

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

namespace Wedeto\DB;

use PHPUnit\Framework\TestCase;

use Wedeto\DB\Schema\Column;
use Wedeto\Util\Validation\ValidationException;
use Wedeto\DB\Model\DBVersion;
use Wedeto\Util\DI\DI;

use Prophecy\Argument;

/**
 * @covers Wedeto\DB\Model
 */
class ModelTest extends TestCase
{
    public function setUp()
    {
        $this->db_mocker = $this->prophesize(DB::class);
        $this->db = $this->db_mocker->reveal();
        DI::startNewContext('test');
        DI::getInjector()->setInstance(DB::class, $this->db);

        $this->dao_mocker = $this->prophesize(DAO::class);
        $this->dao = $this->dao_mocker->reveal();
    }

    public function tearDown()
    {
        DI::destroyContext('test');
    }


    public function testGetTable()
    {
        $this->assertEquals('db_version', DBVersion::getTablename());        
    }

    public function testGetDAO()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $dao = DBVersion::getDAO($this->db);
        $this->assertSame($this->dao, $dao);

        $dao = DBVersion::getDAO();
        $this->assertSame($this->dao, $dao);

        $instance = new DBVersion;
        $this->assertSame($this->db, $instance->getDB());
    }

    public function testSaveModel()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);

        $instance = new DBVersion;

        $this->dao_mocker->save($instance)->shouldBeCalledTimes(1);
        $instance->save();

        $this->dao_mocker->save($instance)->shouldBeCalledTimes(2);
        $instance->insert();
    }

    public function testDeleteModel()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);

        $instance = new DBVersion;

        $this->dao_mocker->delete($instance)->shouldBeCalledTimes(1);
        $instance->delete();
    }

    public function testSourceDB()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);

        $db2 = $this->prophesize(DB::class)->reveal();
        $instance = new DBVersion;

        $this->assertSame($db2, $instance->getDB($db2));
        $this->assertSame($this->db, $instance->getDB());

        $this->assertSame($instance, $instance->setSourceDB($db2), "Fluent interface");
        $this->assertSame($db2, $instance->getDB());
        $this->assertSame($db2, $instance->getSourceDB());
    }

    public function testGetAndSetFields()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->dao_mocker->getColumns()->willReturn(
            [
                'id' => new Column\Serial('id'),
                'module' => new Column\Varchar('module', 128),
                'version' => new Column\Integer('version'),
                'date' => new Column\DateTime('date')
            ]
        );
        $this->dao_mocker->getPrimaryKey()->willReturn(
            [
                'id' => new Column\Serial('id'),
            ]
        );

        $instance = new DBVersion;
        $this->assertSame($instance, $instance->setField('id', 3));
        $this->assertEquals(3, $instance->getField('id'));

        $instance->id = 4;
        $this->assertEquals(4, $instance->id);

        $instance->module = 'foo';
        $this->assertEquals('foo', $instance->getField('module'));

        $now = new \DateTime('now');
        $instance->date = new \DateTime('now');
        $this->assertEquals($now, $instance->getField('date'));

        // Try some invalid values
        $this->assertThrows(function () use ($instance) { $instance->version = "bar"; }, 'Integral value required');
        $this->assertThrows(function () use ($instance, $now) { $instance->version = $now; }, 'Integral value required');
        $this->assertThrows(function () use ($instance, $now) { $instance->module = $now; }, 'String required');

        $rec = $instance->getRecord();
        $this->assertTrue(is_array($rec));
        $this->assertEquals(4, $rec['id']);
        $this->assertEquals('foo', $rec['module']);
        $this->assertEquals($now, $rec['date']);

        $chg = $instance->getChanges();
        $this->assertTrue(isset($chg['id']));
        $this->assertTrue(isset($chg['module']));
        $this->assertTrue(isset($chg['date']));
        $this->assertFalse(isset($chg['version']));

        $this->assertSame($instance, $instance->setChanged('module', false));
        $chg = $instance->getChanges();
        $this->assertFalse(isset($chg['module']));
        $instance->markClean();

        $chg = $instance->getChanges();
        $this->assertFalse(isset($chg['id']));
        $this->assertFalse(isset($chg['module']));
        $this->assertFalse(isset($chg['date']));
        $this->assertFalse(isset($chg['version']));

        $instance->version = 99;
        $this->assertEquals(99, $instance->version);
        $instance->setChanged('module', true);
        $chg = $instance->getChanges();
        $this->assertTrue(isset($chg['version']));
        $this->assertTrue(isset($chg['module']));
        
        $this->assertSame($instance, $instance->setID(42));
        $this->assertEquals(42, $instance->getID());

    }

    protected function assertThrows($fn, $msg)
    {
        $thrown = false;
        try
        {
            $fn();
        }
        catch (ValidationException $e)
        {
            $this->assertContains($msg, $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown, "Exception should be thrown");
    }
}
