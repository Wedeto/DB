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

use Wedeto\DB\Driver\Driver;

use Wedeto\DB\Exception\TableNotExistsException;
use Wedeto\DB\Exception\MigrationException;
use Wedeto\DB\Exception\NoMigrationTableException;

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
        $config->set('sql', 'lazy', false);
        $config->set('sql', 'username', 'foo');
        $config->set('sql', 'password', 'bar');
        $config->set('sql', 'hostname', 'localhost');
        $config->set('sql', 'database', 'foobardb');
        $config->set('sql', 'type', 'mysql');

        $this->config = $config;
    }


    public function testConstruction()
    {
        $mocker = $this->prophesize(\PDO::class);
        $pdo = $mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
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

    public function testConstructionWithUpperCaseType()
    {
        $this->config->set('sql', 'type', 'pgSQL');
        $mocker = $this->prophesize(\PDO::class);
        $pdo = $mocker->reveal();
        DI::getInjector()->registerFactory(\PDO::class, new BasicFactory(function (array $args) use ($pdo) {
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
