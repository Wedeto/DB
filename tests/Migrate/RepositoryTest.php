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
use Wedeto\DB\Exception\MigrationException;

/**
 * @covers Wedeto\DB\Migrate\Repository
 */
class RepositoryTest extends TestCase
{
    public function testNormalize()
    {
        $normalized = Repository::normalizeModule('Foo/Bar\\Bar');
        $expected = "foo.bar.bar";

        $this->assertEquals($expected, $normalized, "Forward slash, backslash and dot should be normalized to dot, and string should be lowercased");
    }


    public function testAddModuleFromObject()
    {
        $db = $this->prophesize(DB::class)->reveal();
        $repo = new Repository($db);

        $prophecy = $this->prophesize(Module::class);
        $prophecy->getModule()->willReturn("My/Module");
        $prophecy->getDB()->willReturn($db);

        $module = $prophecy->reveal();
        $repo->addModule($module);
        
        $this->assertEquals($module, $repo->getMigration('My/Module'));
        $this->assertEquals($module, $repo->getMigration('My.Module'));
        $this->assertEquals($module, $repo->getMigration('My\\Module'));
    }

    public function testAddModuleFromNameAndIterate()
    {
        $db = $this->prophesize(DB::class)->reveal();
        $repo = new Repository($db);

        $mod = "Foo.bar";
        $repo->addMigration($mod, __DIR__ . "/migrations1");

        $mod = strtolower($mod);
        $res = [];
        foreach ($repo as $module => $obj)
            $res[$module] = $obj;

        $this->assertTrue(isset($res[$mod]), "Module should be registered");
        $this->assertEquals($mod, $res[$mod]->getModule());
    }

    public function testAddDuplicateModuleShouldThrowException()
    {
        $db = $this->prophesize(DB::class)->reveal();
        $repo = new Repository($db);

        $mod = "Foo.bar";
        $repo->addMigration($mod, __DIR__ . "/migrations1");

        $mod = strtolower($mod);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage("Duplicate module: $mod");
        $repo->addMigration($mod, __DIR__ . "/migrations2");
    }

    public function testAddModuleWithDifferentDBShouldThrowException()
    {
        $db = $this->prophesize(DB::class)->reveal();
        $db2 = $this->prophesize(DB::class)->reveal();

        $mod = "Foo.bar";
        $path = __DIR__ . "/migrations1";

        $repo = new Repository($db);
        $module = new Module($mod, $path, $db2);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage("The DB instances should be the same");
        $repo->addModule($module);
    }
    
}
