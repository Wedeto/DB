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

namespace Wedeto\DB\Migrate;

use PHPUnit\Framework\TestCase;

use Wedeto\DB\DB;
use Wedeto\DB\DAO;
use Wedeto\DB\Driver\Driver;
use Wedeto\DB\Schema\Schema;
use Wedeto\DB\Schema\Table;
use Wedeto\DB\Schema\Column;
use Wedeto\DB\Model\DBVersion;

use Wedeto\Util\DI\DI;

use Prophecy\Argument;

/**
 * @covers Wedeto\DB\Migrate\Module
 */
class ModuleTest extends TestCase
{
    private $schema_mocker;
    private $result_mocker;
    private $drv_mocker;
    private $db_mocker;
    private $module_mocker;
    private $repository_mocker;
    private $dao_mocker;

    private $db;
    private $dao;

    public function setUp()
    {
        $version_column = new Column\TInt('version');
        $mod_column = new Column\TVarchar('module', 128);

        $this->table_mocker = $this->prophesize(Table::class);
        $this->table_mocker->getColumns()->willReturn([
            'version' => $version_column,
            'module' => $mod_column,
        ]);
        $this->table_mocker->getPrimaryColumns()->willReturn(['version' => $version_column]);

        $this->schema_mocker = $this->prophesize(Schema::class);
        $this->schema_mocker->getTable(DBVersion::tablename())->willReturn($this->table_mocker->reveal());

        $this->result_mocker = $this->prophesize(\PDOStatement::class);
        $this->result_mocker->fetch()->willReturn(['version' => 1, 'module' => 'wedeto.db']);

        $this->drv_mocker = $this->prophesize(Driver::class);
        $this->drv_mocker->select(Argument::any())->willReturn($this->result_mocker->reveal());

        $this->db_mocker = $this->prophesize(DB::class);
        //$this->db_mocker->getSchema()->willReturn($this->schema_mocker->reveal());
        //$this->db_mocker->getDriver()->willReturn($this->drv_mocker->reveal());

        $this->module_mocker = $this->prophesize(Module::class);

        $this->repository_mocker = $this->prophesize(Repository::class);
        $this->repository_mocker->getMigration('Wedeto.DB')->willReturn($this->module_mocker->reveal());

        $this->dao_mocker = $this->prophesize(DAO::class);

        $this->dao = $this->dao_mocker->reveal();
        $this->repo = $this->repository_mocker->reveal();
        $this->db = $this->db_mocker->reveal();
        DI::startNewContext('dbtest');
        DI::getInjector()->setInstance(DB::class, $this->db);
    }

    public function tearDown()
    {
        DI::destroyContext('dbtest');
    }

    public function testGetModule()
    {
        $mod = new Module('Foo.Bar', __DIR__ . '/files', $this->repo, $this->db);
        $this->assertEquals('Foo.Bar', $mod->getModule());
    }

    public function testGetLatestVersion()
    {
        $version_mock = $this->prophesize(DBVersion::class);
        $version_mock->getField('version')->willReturn(1);
        $version = $version_mock->reveal();

        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn($version);
        $mod = new Module('Foo.Bar', __DIR__ . '/files', $this->repo, $this->db);

        $this->assertEquals(2, $mod->getLatestVersion());
        $this->assertEquals(1, $mod->getCurrentVersion());
        $this->assertFalse($mod->isUpToDate());
    }


    public function testUpgradeToLatest()
    {
        $version_mock = $this->prophesize(DBVersion::class);
        $version_mock->getField('version')->willReturn(1);
        $version = $version_mock->reveal();
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);

        $this->dao_mocker->getColumns()->willReturn([
            "id" => new Column\TSerial('id'),
            "module" => new Column\TVarchar('module', 128),
            "version" => new Column\TInt('version'),
            "date_upgraded" => new Column\TDateTime('date_upgraded'),
        ]);

        $this->dao_mocker->getPrimaryKey()->willReturn([
            "id" => new Column\TSerial('id')
        ]);

        $this->dao_mocker->save(Argument::any())->shouldBeCalled();
        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn($version);

        $mod = new Module('Foo.Bar', __DIR__ . '/files', $this->repo, $this->db);
        $filename = __DIR__ . '/files/1-to-2.php';

        unset($GLOBALS['_wedeto_db_test_args']);
        $this->db_mocker->beginTransaction()->shouldBeCalled();
        $this->db_mocker->commit()->shouldBeCalled();

        $this->assertEquals(2, $mod->getLatestVersion());
        $this->assertEquals(1, $mod->getCurrentVersion());
        $mod->upgradeToLatest();
        
        $this->assertEquals($this->db, $GLOBALS['_wedeto_db_test_args'][0], "Database was not set");
        $this->assertEquals($filename, $GLOBALS['_wedeto_db_test_args'][1], "Filename was not set");

        $this->db_mocker->beginTransaction()->shouldBeCalled();
        $this->drv_mocker->delete(Argument::any());
        $this->db_mocker->commit()->shouldBeCalled();

        $sql_filename = __DIR__ . '/files/1-to-0.sql';
        $this->db_mocker->executeSQL($sql_filename)->shouldBeCalled();
        $mod->uninstall();
        unset($GLOBALS['_wedeto_db_test_args']);
    }
}
