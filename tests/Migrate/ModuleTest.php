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
use Wedeto\DB\Query;

use Wedeto\DB\Exception\TableNotExistsException;
use Wedeto\DB\Exception\MigrationException;
use Wedeto\DB\Exception\NoMigrationTableException;

use Wedeto\Util\DI\DI;

use Prophecy\Argument;

/**
 * @covers Wedeto\DB\Migrate\Module
 */
class ModuleTest extends TestCase
{
    private $result_mocker;
    private $drv_mocker;
    private $db_mocker;
    private $module_mocker;
    private $dao_mocker;

    private $db;
    private $dao;

    public function setUp()
    {
        $version_column = new Column\Integer('to_version');
        $mod_column = new Column\Varchar('module', 128);

        $this->result_mocker = $this->prophesize(\PDOStatement::class);
        $this->result_mocker->fetch()->willReturn(['to_version' => 1, 'module' => 'wedeto.db']);

        $this->drv_mocker = $this->prophesize(Driver::class);
        $this->drv_mocker->select(Argument::any())->willReturn($this->result_mocker->reveal());

        $this->db_mocker = $this->prophesize(DB::class);

        $this->module_mocker = $this->prophesize(Module::class);

        $this->dao_mocker = $this->prophesize(DAO::class);

        $this->dao = $this->dao_mocker->reveal();
        $this->db = $this->db_mocker->reveal();
        $this->drv = $this->drv_mocker->reveal();
        DI::startNewContext('dbtest');
        DI::getInjector()->setInstance(DB::class, $this->db);

        unset($GLOBALS['MIGRATE_VERSION_FILES']);
    }

    public function tearDown()
    {
        DI::destroyContext('dbtest');
    }

    public function testGetModule()
    {
        $mod = new Module('Foo.Bar', __DIR__ . '/migrations1', $this->db);
        $this->assertEquals('Foo.Bar', $mod->getModule());
    }

    public function testGetLatestVersion()
    {
        $version_mock = $this->prophesize(DBVersion::class);
        $version_mock->getField('to_version')->willReturn(1);
        $version = $version_mock->reveal();

        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn($version);
        $mod = new Module('Foo.Bar', __DIR__ . '/migrations1', $this->db);

        $this->assertEquals(2, $mod->getLatestVersion());
        $this->assertEquals(1, $mod->getCurrentVersion());
        $this->assertFalse($mod->isUpToDate());
    }

    public function testSetupCoreSucceeds()
    {
        $root = dirname(dirname(__DIR__ ));
        $sql = $root . DIRECTORY_SEPARATOR . 'migrations';

        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->dao_mocker
            ->get(Argument::type(Query\WhereClause::class), Argument::type(Query\OrderClause::class))
            ->willThrow(new TableNotExistsException());
        
        $module = new Module('Wedeto.DB', $sql, $this->db);
        $this->assertSame($this->db, $module->getDB());
        $this->assertEquals($sql, $module->getPath());
        $this->assertEquals(0, $module->getCurrentVersion());
        $this->assertEquals(1, $module->getLatestVersion());

        // Execute migation
        // We require a lot of mockery here
        $this->db_mocker->beginTransaction()->shouldBeCalled();
        $this->db_mocker->commit()->shouldBeCalled();
        $this->db_mocker->getDriver()->willReturn($this->drv);
        $this->setupVersionDAO($this->dao_mocker);
        $this->dao_mocker->save(Argument::type(DBVersion::class))->shouldBeCalled();

        $this->drv_mocker->createTable(Argument::type(Table::class))->shouldBeCalled();
        $module->upgradeToLatest();
    }

    public function testSetupOtherTableWithoutCoreFails()
    {
        $root = dirname(dirname(__DIR__ ));
        $sql = $root . DIRECTORY_SEPARATOR . 'migrations';

        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->dao_mocker
            ->get(Argument::type(Query\WhereClause::class), Argument::type(Query\OrderClause::class))
            ->willThrow(new TableNotExistsException());
        
        $module = new Module('Foo.Bar', $sql, $this->db);
        $this->expectException(NoMigrationTableException::class);
        $module->getCurrentVersion();
    }

    /**
     * @covers Wedeto\DB\Migrate\executePHP
     */
    public function testUpgradeToLatest()
    {
        $version_mock = $this->prophesize(DBVersion::class);
        $version_mock->getField('to_version')->willReturn(1);
        $version = $version_mock->reveal();
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->setupVersionDAO($this->dao_mocker);

        $this->dao_mocker->save(Argument::any())->shouldBeCalled();
        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn($version);

        $mod = new Module('Foo.Bar', __DIR__ . '/migrations1', $this->db);
        $filename = __DIR__ . '/migrations1/1-to-2.php';

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

        $sql_filename = __DIR__ . '/migrations1/1-to-0.sql';
        $this->db_mocker->executeSQL($sql_filename)->shouldBeCalled();
        $mod->uninstall();
        unset($GLOBALS['_wedeto_db_test_args']);
    }

    /**
     * @covers Wedeto\DB\Migrate\executePHP
     */
    public function testUpgradeThrowsExceptionRollback()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->setupVersionDAO($this->dao_mocker);

        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn(null);

        $mod = new Module('Foo.Bar', __DIR__ . '/migrations2', $this->db);
        $filename = __DIR__ . '/migrations2/0-to-1.php';

        $this->assertEquals(1, $mod->getLatestVersion());
        $this->assertEquals(0, $mod->getCurrentVersion());

        // Predict the steps of the migration
        $this->db_mocker->beginTransaction()->shouldBeCalled();
        $this->db_mocker->rollBack()->shouldBeCalled();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Testexception");
        $mod->upgradeToLatest();
    }
    
    private function setupVersionDAO($mocker)
    {
        $mocker->getColumns()->willReturn([
            "id" => new Column\Serial('id'),
            "module" => new Column\Varchar('module', 128),
            "to_version" => new Column\Integer('version'),
            "migration_date" => new Column\DateTime('migration_date'),
        ]);

        $mocker->getPrimaryKey()->willReturn([
            "id" => new Column\Serial('id')
        ]);
    }

    public function testUpgradeToSameVersion()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->setupVersionDAO($this->dao_mocker);

        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn(null);

        $mod = new Module('Foo.Bar', __DIR__ . '/migrations2', $this->db);
        $this->assertEquals(1, $mod->getLatestVersion());
        $this->assertEquals(0, $mod->getCurrentVersion());

        // Migrating to same version shouldn't do anything
        $mod->migrateTo(0);

        $this->assertEquals(0, $mod->getCurrentVersion());
    }

    public function testUpgradeToUnreachableVersion()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->setupVersionDAO($this->dao_mocker);
        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn(null);

        $mod = new Module('Foo.Bar', __DIR__ . '/migrations3', $this->db);
        $this->assertEquals(3, $mod->getLatestVersion());
        $this->assertEquals(0, $mod->getCurrentVersion());

        // Migrating to same version shouldn't do anything
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage("No migration path from version 1 to 3 for module Foo.Bar");
        $mod->migrateTo(3);
    }

    public function testUpgradePathWithShortcuts()
    {
        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->setupVersionDAO($this->dao_mocker);
        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn(null);

        $dir = __DIR__ . '/migrations4';
        $mod = new Module('Foo.Baz', $dir, $this->db);
        $this->assertEquals(10, $mod->getLatestVersion());
        $this->assertEquals(0, $mod->getCurrentVersion());
        
        // Should find trajectory 0 -> 3 -> 4 -> 10, so 3 migrations
        $this->db_mocker->beginTransaction()->shouldBeCalledTimes(3);
        $this->db_mocker->commit()->shouldBeCalledTimes(3);
        $this->dao_mocker->save(Argument::type(DBVersion::class))->shouldBeCalledTimes(3);

        // Migrating to same version shouldn't do anything
        $mod->migrateTo(10);
        
        $this->assertEquals(
            [
                $dir . '/0-to-3.php',
                $dir . '/3-to-4.php',
                $dir . '/4-to-10.php',
            ],
            $GLOBALS['MIGRATE_VERSION_FILES']
        );
    }

    public function testDowngradePathWithShortcuts()
    {
        // Set the module to version 10
        $version_mock = $this->prophesize(DBVersion::class);
        $version_mock->getField('to_version')->willReturn(10);
        $version = $version_mock->reveal();

        $this->db_mocker->getDAO(DBVersion::class)->willReturn($this->dao);
        $this->setupVersionDAO($this->dao_mocker);
        $this->dao_mocker->get(Argument::any(), Argument::any())->willReturn($version);

        $dir = __DIR__ . '/migrations4';
        $mod = new Module('Foo.Baz', $dir, $this->db);
        $this->assertEquals(10, $mod->getLatestVersion());
        $this->assertEquals(10, $mod->getCurrentVersion());
        
        // Downgrade should find trajectory 10 -> 9 -> 0, so 2 migrations
        $this->db_mocker->beginTransaction()->shouldBeCalledTimes(2);
        $this->db_mocker->commit()->shouldBeCalledTimes(2);
        $this->dao_mocker->save(Argument::type(DBVersion::class))->shouldBeCalledTimes(2);

        $mod->uninstall();

        $this->assertEquals(
            [
                $dir . '/10-to-9.php',
                $dir . '/9-to-0.php',
            ],
            $GLOBALS['MIGRATE_VERSION_FILES']
        );
    }
}
