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

namespace WASP\DB\Query;

use WASP\DB\Driver\Driver;
use InvalidArgumentException;
use OutOfRangeException;

class Parameters
{
    protected $params = array();
    protected $tables = array();
    protected $aliases = array();
    protected $column_counter = 0;
    protected $table_counter = 0;
    protected $db;

    public function __construct(Driver $database)
    {
        $this->db = $database;
    }

    public function getDB()
    {
        return $this->db;
    }

    public function assign($value)
    {
        $key = $this->getNextKey();
        $this->params[$key] = $value;
        return $key;
    }

    public function get($key)
    {
        if (!array_key_exists($key, $this->params))
            throw new OutOfRangeException("Invalid key: $key");

        return $this->params[$key];
    }

    public function set($key, $value)
    {
        $this->params[$key] = $value;
        return $this;
    }

    public function getNextKey()
    {
        return "c" . $this->column_counter++;
    }

    public function &getParameters()
    {
        return $this->params;
    }

    public function getTable(string $table)
    {
        return $this->tables[$table];
    }

    public function reset()
    {
        $this->tables = array();
        $this->aliases = array();
        $this->params = array();
    }

    public function registerTable($name, $alias)
    {
        if (!empty($alias) && empty($name))
            return;

        if (isset($this->tables[$name]))
        {
            if (empty($alias))
                throw new InvalidArgumentException("Duplicate table without an alias: $name");
            if (isset($this->tables[$name][$alias]))
                throw new InvalidArgumentException("Duplicate alias $alias for table $name");
            if (isset($this->tables[$name][$name]))
                throw new InvalidArgumentException("All instances of a table reference must be aliased if used more than once");

            $this->tables[$name][$alias] = true;
            $this->aliases[$alias] = $name;
        }
        elseif (!empty($alias) && is_string($alias))
        {
            if (isset($this->aliases[$alias]))
                throw new InvalidArgumentException("Duplicate alias $alias for table $name - also referring to {$this->aliases[$alias]}");
            $this->aliases[$alias] = $name;
            $this->tables[$name][$alias] = true;
        }
        else
        {
            $this->tables[$name][$name] = true;
        }
    }

    public function resolveTable($name)
    {
        if (!empty($name) && is_string($name))
        {
            if (isset($this->aliases[$name]))
                return array($this->aliases[$name], $name);

            if (isset($this->tables[$name]))
            {
                if (count($this->tables[$name]) === 1)
                    return array($name, null);

                throw new InvalidArgumentException("Multiple references to $name, use the appropriate alias");
            }

            throw new InvalidArgumentException("Unknown source table $name");
        }

        throw new InvalidArgumentException("No table identifier provided");
    }

    public function getDefaultTable()
    {
        if (count($this->tables) <= 1 && count($this->aliases) <= 1)
            return null;

        $keys = array_keys($this->tables);
        $first = $keys[0];

        $akeys = array_keys($this->tables[$first]);
        $alias = $akeys[0];

        if ($alias === $first)
            return new TableClause($first);
        else
            return new TableClause($alias);
    }

    public function __debugInfo()
    {
        return array(
            "params" => $this->params,
            "tables" => $this->tables,
            "aliases" => $this->aliases,
            "column_counter" => $this->column_counter,
            "table_counter " => $this->table_counter
        );
    }
}

