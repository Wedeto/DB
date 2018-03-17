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

use Wedeto\DB\Driver;
use Wedeto\DB\Schema;
use Wedeto\DB\Query;

use Wedeto\DB\Exception\TableNotExistsException;
use Wedeto\DB\Exception\MigrationException;
use Wedeto\DB\Exception\NoMigrationTableException;
use Wedeto\DB\Exception\DriverException;
use Wedeto\DB\Exception\ConfigurationException;
use Wedeto\DB\Exception\DAOException;
use Wedeto\DB\Exception\IOException;
use Wedeto\DB\Model\DBVersion;

use Wedeto\Util\Validation\Type;
use Wedeto\Util\DI\DI;
use Wedeto\Util\DI\BasicFactory;
use Wedeto\Util\Configuration;


use Prophecy\Argument;

/**
 * @covers Wedeto\DB\DB
 */
class DBTest extends TestCase
{
    private $config;

    public function setUp()
    {
        $config = new Configuration();
        $config->setType('sql', Type::ARRAY);

        $config->set('sql', 'default', 'lazy', false);
        $config->set('sql', 'default', 'username', 'foo');
        $config->set('sql', 'default', 'password', 'bar');
        $config->set('sql', 'default', 'hostname', 'localhost');
        $config->set('sql', 'default', 'database', 'foobardb');
        $config->set('sql', 'default', 'type', 'mysql');

        $this->config = new DBConfig($config);
    }


    public function testConstruction()
    {
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) {
            return new MockPDO($args['dsn'], $args['username'], $args['password']);
        }));

        $db = new DB($this->config);
        $pdo = $db->getPDO();
        $this->assertInstanceOf(MockPDO::class, $pdo);

        $args = $pdo->args;
        $this->assertEquals('mysql:host=localhost;dbname=foobardb;charset=utf8', $args[0]);
        $this->assertEquals('foo', $args[1]);
        $this->assertEquals('bar', $args[2]);

        $di = $db->__debuginfo();
        $this->assertTrue(isset($di['dsn']), "DSN should be set");
        $this->assertTrue(isset($di['driver']), "Driver should be set");
    }

    public function testGetPDOWithLazyLoading()
    {
        $this->config->set('lazy', true);
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) {
            return new MockPDO($args['dsn'], $args['username'], $args['password']);
        }));

        $db = new DB($this->config);
        $pdo = $db->getPDO();
        $this->assertInstanceOf(MockPDO::class, $pdo);

        $args = $pdo->args;
        $this->assertEquals('mysql:host=localhost;dbname=foobardb;charset=utf8', $args[0]);
        $this->assertEquals('foo', $args[1]);
        $this->assertEquals('bar', $args[2]);
    }

    public function testGetDriverWithLazyLoading()
    {
        $this->config->set('lazy', true);
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) {
            return new MockPDO($args['dsn'], $args['username'], $args['password']);
        }));

        $db = new DB($this->config);

        $driver = $db->getDriver();
        $this->assertInstanceOf(Driver\Driver::class, $driver);
    }

    public function testConstructionWithSubclassedPGSQL()
    {
        $this->config->set('type', MockDriver::class);
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) {
            return new MockPDO($args['dsn'], $args['username'], $args['password']);
        }));

        $db = new DB($this->config);

        $pdo = $db->getPDO();
        $this->assertInstanceOf(MockPDO::class, $pdo);

        $args = $pdo->args;
        $this->assertEquals('pgsql:host=localhost;dbname=foobardb', $args[0]);
        $this->assertEquals('foo', $args[1]);
        $this->assertEquals('bar', $args[2]);
    }

    public function testConstructionWithNonExistingDriver()
    {
        $this->config->set('type', 'Wedeto\\DB\\Non\\Existing\\Driver');
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage("No driver available for database type");
        $db = new DB($this->config);
    }

    public function testConstructionWithNoDriver()
    {
        unset($this->config['type']);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Please specify the database type in the configuration section [sql]");
        $db = new DB($this->config);
    }

    public function testDBDelegatesToPDO()
    {
        $this->config->set('lazy', true);
        $pdo_mocker = $this->prophesize(\PDO::class);
        $pdo = $pdo_mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
            return $pdo;
        }));

        $this->config->set('type', 'MySQL');
        $db = new DB($this->config);

        $this->assertSame($pdo, $db->getPDO());
        $pdo = $db->getPDO();

        $pdo_mocker->beginTransaction()->shouldBeCalledTimes(1);
        $db->beginTransaction();

        $pdo_mocker->commit()->shouldBeCalledTimes(1);
        $db->commit();

        $pdo_mocker->rollBack()->shouldBeCalledTimes(1);
        $db->rollBack();

        $pdo_mocker->exec("Foo")->shouldBeCalled();
        $db->exec("Foo");

        $pdo_mocker->prepare("BAR")->shouldBeCalled();
        $db->prepare("BAR");

        $pdo_mocker->setAttribute(Argument::type('int'), Argument::type('int'))->shouldBeCalledTimes(4);
        $db = new DB($this->config);
        $pdo_mocker->quote("FOOBAR")->shouldBeCalled();
        $db->quote("FOOBAR");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Function nonExisting does not exist");
        $db->nonExisting("FUNC");
    }

    public function testGetSchemaReturnsSchema()
    {
        $pdo_mocker = $this->prophesize(\PDO::class);
        $pdo = $pdo_mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
            return $pdo;
        }));
        $db = new DB($this->config);

        $schema_mocker = $this->prophesize(Schema\Schema::class);
        $schema_mock = $schema_mocker->reveal();

        $received_args = [];
        DI::getInjector()->registerFactory(
            Schema\Schema::class, 
            new BasicFactory(function (array $args) use ($schema_mock, &$received_args) {
                foreach ($args as $k => $v)
                    $received_args[$k] = $v;
                return $schema_mock;
            })
        );

        $schema = $db->getSchema();
        $this->assertSame($schema_mock,$schema);
        $expectedName = "mysql_localhost_foobardb_foobardb";
        $this->assertEquals($expectedName, $received_args['schema_name']);
    }

    public function testGetDAOReturnsProperDAO()
    {
        $pdo_mocker = $this->prophesize(\PDO::class);
        $pdo = $pdo_mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
            return $pdo;
        }));

        $db = new DB($this->config);
        $dao = $db->getDAO(DBVersion::class);
        $this->assertInstanceOf(DAO::class, $dao, "A DAO instance should be returned");
        $this->assertEquals(DBVersion::getTablename(), $dao->getTablename(), "The table name should match");

        $dao2 = $db->getDAO(MockModel::class);
        $this->assertNotSame($dao, $dao2, "A different DAO should be returned for a different Model");
        $this->assertInstanceOf(DAO::class, $dao2, "A DAO instance should be returned");
        $this->assertEquals(MockModel::getTablename(), $dao2->getTablename(), "The table name should match");

        $db->setDAO(MockModel::class, $dao);
        $dao3 = $db->getDAO(MockModel::class);
        $this->assertSame($dao, $dao3, "The overridden DAO should be returned");

        $dao4 = $db->getDAO(MockModel::class);
        $this->assertSame($dao3, $dao4, "The same overridden DAO should be returned again");
    }

    public function testGetDAOWithInvalidClass()
    {
        $pdo_mocker = $this->prophesize(\PDO::class);
        $pdo = $pdo_mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
            return $pdo;
        }));
        $db = new DB($this->config);
        
        $this->expectException(DAOException::class);
        $this->expectExceptionMessage("is not a valid Model");
        $dao = $db->getDAO(\Stdclass::class);
    }

    public function testSetDAOWithInvalidClass()
    {
        $pdo_mocker = $this->prophesize(\PDO::class);
        $pdo = $pdo_mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
            return $pdo;
        }));
        $db = new DB($this->config);
        
        $dao_mocker = $this->prophesize(DAO::class);
        $dao = $dao_mocker->reveal();

        $this->expectException(DAOException::class);
        $this->expectExceptionMessage("is not a valid Model");
        $dao = $db->setDAO(\Stdclass::class, $dao);
    }

    public function testPrepareQuery()
    {
        $this->config->set('lazy', true);
        $pdo_mocker = $this->prophesize(\PDO::class);
        $pdo_mocker->setAttribute(3, 2)->shouldBeCalledTimes(1);
        $pdo_mocker->setAttribute(19, 2)->shouldBeCalledTimes(1);
        $pdo = $pdo_mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
            return $pdo;
        }));
        $db = new DB($this->config);

        $drv_mocker = $this->prophesize(Driver\PGSQL::class);
        $drv = $drv_mocker->reveal();

        DI::getInjector()->registerFactory(Driver\PGSQL::class, new BasicFactory(function (array $args) use ($drv) {
            echo "BUILDING PGSQL\n";
            return $drv;
        }));

        $st_mocker = $this->prophesize(\PDOStatement::class);
        $st = $st_mocker->reveal();

        $pdo_mocker->prepare(Argument::any())->willReturn($st);

        $query_mocker = $this->prophesize(Query\Query::class);
        $statement = $db->prepareQuery($query_mocker->reveal());

        $this->assertSame($st, $statement);
    }

    public function testExecuteSQL()
    {
        $this->config->set('lazy', false);
        $pdo_mocker = $this->prophesize(\PDO::class);
        $pdo = $pdo_mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
            return $pdo;
        }));
        $db = new DB($this->config);

        $db->getDriver()->setTablePrefix("WDIFB");
        $pdo_mocker->exec("SELECT * FROM foo;")->shouldBeCalledTimes(1);
        $pdo_mocker->exec("DROP TABLE foo;")->shouldBeCalledTimes(1);
        $pdo_mocker->exec("CREATE TABLE WDIFBtab (id integer primary key auto_increment, varchar(32) foo);")->shouldBeCalledTimes(1);

        $file = __DIR__ . DIRECTORY_SEPARATOR . '/testStatements.sql';
        $db->executeSQL($file);

        $file = __DIR__ . DIRECTORY_SEPARATOR . '/nonExistingTestStatements.sql';

        $this->expectException(IOException::class);
        $this->expectExceptionMessage("Unable to open file '$file'");
        $db->executeSQL($file);
    }
}

class MockPDO extends \PDO
{
    public $args;

    public function __construct($dsn, $username, $password)
    {
        $this->args = func_get_args();
    }

    public function setAttribute($attrib, $value)
    { }
}

class MockDriver extends Driver\PGSQL
{ }

class MockModel extends Model
{ 
    protected static $_table = "foobar";
}
