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

namespace WASP\DB\Schema;

use WASP\Dictionary;
use WASP\Cache;
use WASP\DB\DBException;

class Schema
{
    protected $name;
    protected $tables;

    public function __construct(string $schema_name, bool $use_cache)
    {
        $this->schema_name = $name;
        if ($use_cache)
            $this->loadCache();
        else
            $this->tables = new Dictionary;
    }

    public function loadCache()
    {
        if (empty($schema_name))
            throw new DBException("Please provide a name for the schema when using the cache");

        $this->tables = new Cache('dbschema_' . $this->schema_name);
    }

    public function getTable($table)
    {
        if (!isset($this->tables->has($table)))
            throw new DBException("Table $table not ofund");

        return $this->tables[$table];
    }

    public function putTable(Table $table)
    {
        $this->tables[$table->getName()] = $table;
    }

    public function removeTable($table)
    {
        if ($table instanceof Table)
            $table = $table->getName();
        unset($this->tables[$table]);
    }
}
