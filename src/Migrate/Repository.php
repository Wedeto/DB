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

use Wedeto\DB\Exception\MigrationException;
use Wedeto\Model\DBVersion;

/**
 * Keeps track of the registered modues
 */
class Repository
{
    protected $modules = [];

    /**
     * @param string The module to return
     * @return Module The migration module
     */
    public function getMigration($module)
    {
        return $this->modules[self::normalizeModule($module)] ?? null;
    }

    /**
     * Register a module that has already been instantiated
     * @param $module The module to register
     * @return Repository Provide fluent interface
     */
    public function addModule(Module $module)
    {
        $name = self::normalizeModule($module->getModule());
        $this->modules[$name] = $module;
        return $this;
    }

    /**
     * Add a new migration using a module name and a path
     * 
     * The module object will be instantiated and registered.
     *
     * @return Module The module instance
     */
    public function addMigration(string $module, string $path)
    {
        $module = self::normalizeModule($module);
        if (isset($this->modules[$module]))
            throw new MigrationException("Duplicate module: " . $module);

        $instance = new Module($module, $path);
        $this->addModule($module, $instance);
        return $instance;
    }

    /**
     * Normalize the module name: lowercased and with backslashes replaced by dots.
     * This allows the use of namespace names as modules.
     *
     * @param string $module The module name to normalize
     * @return string The normalized module name
     */
    public static function normalizeModule(string $module)
    {
        return strtolower(preg_replace('/([\.\\/\\\\])/', '.', $module));
    }
}
