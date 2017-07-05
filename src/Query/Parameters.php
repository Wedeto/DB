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

use Wedeto\DB\Driver\Driver;
use Wedeto\DB\Exception\QueryException;
use Wedeto\DB\Exception\ImplementationException;
use Wedeto\DB\Exception\ConfigurationException;
use Wedeto\DB\Exception\OutOfRangeException;
use ArrayIterator;
use PDO;

class Parameters implements \Iterator
{
    protected $driver = null;
    protected $params = array();
    protected $param_types = array();
    protected $tables = array();
    protected $aliases = array();
    protected $field_aliases = [];

    protected $column_counter = 0;
    protected $table_counter = 0;
    protected $scope_counter = 0;
    protected $scope_id = 0;

    protected $parent_scope = null;
    protected $scopes = [];

    /** For the iterator interface */
    protected $iterator = null;

    public function __construct(Driver $driver = null)
    {
        if ($driver !== null)
            $this->setDriver($driver);
    }

    public function assign($value, $type = PDO::PARAM_STR)
    {
        $key = $this->getNextKey();
        $this->params[$key] = $value;
        $this->param_types[$key] = $type;
        return $key;
    }

    public function get(string $key)
    {
        if (!array_key_exists($key, $this->params))
            throw new OutOfRangeException("Invalid key: $key");

        return $this->params[$key];
    }

    public function getParameterType(string $key)
    {
        if (!array_key_exists($key, $this->params))
            throw new OutOfRangeException("Invalid key: $key");

        return $this->param_types[$key];
    }

    public function set(string $key, $value, $type = PDO::PARAM_STR)
    {
        $this->params[$key] = $value;
        $this->param_types[$key] = $type;
        return $this;
    }

    public function getScopeID()
    {
        return $this->scope_id;
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

        if (!empty($alias) && !empty($this->parent_scope))
        {
            $table = $this->parent_scope->resolveAlias($alias); 
            if (!empty($table))
                throw new QueryException("Duplicate alias \"$alias\" - was already bound to \"$table\" in parent scope");
        }

        if (isset($this->tables[$name]))
        {
            if (empty($alias))
                throw new QueryException("Duplicate table without an alias: \"$name\"");
            if (isset($this->tables[$name][$alias]))
                throw new QueryException("Duplicate alias \"$alias\" for table \"$name\"");
            if (isset($this->tables[$name][$name]))
                throw new QueryException("All instances of a table reference must be aliased if used more than once");

            $this->tables[$name][$alias] = true;
            $this->aliases[$alias] = $name;
        }
        elseif (!empty($alias) && is_string($alias))
        {
            if (isset($this->aliases[$alias]))
                throw new QueryException("Duplicate alias \"$alias\" for table \"$name\" - also referring to \"{$this->aliases[$alias]}\"");
            $this->aliases[$alias] = $name;
            $this->tables[$name][$alias] = true;
        }
        else
        {
            $this->tables[$name][$name] = true;
        }
    }

    public function resolveAlias(string $alias)
    {
        if (isset($this->aliases[$alias]))
            return $this->aliases[$alias];

        if (empty($this->parent_scope))
            return null;

        return $this->parent_scope->resolveAlias($alias);
    }

    public function resolveTable(string $name)
    {
        if (!empty($name) && is_string($name))
        {
            if (isset($this->aliases[$name]))
                return array($this->aliases[$name], $name);

            if (isset($this->tables[$name]))
            {
                if (count($this->tables[$name]) === 1)
                    return array($name, null);
                throw new QueryException("Multiple references to $name, use the appropriate alias");
            }

            if (!empty($this->parent_scope))
                return $this->parent_scope->resolveTable($name);

            throw new QueryException("Unknown source table $name");
        }

        throw new QueryException("No table identifier provided");
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

    public function current()
    {
        return $this->iterator->current();
    }

    public function key()
    {
        return $this->iterator->key();
    }

    public function next()
    {
        $this->iterator->next();
    }

    public function rewind()
    {
        $this->iterator = new ArrayIterator($this->params);
        $this->iterator->rewind();
    }

    public function valid()
    {
        return $this->iterator->valid();
    }

    public function parameterType()
    {
        return $this->getParameterType($this->key());
    }

    public function setDriver(Driver $driver)
    {
        $this->driver = $driver;
        return $this;
    }

    public function getDriver()
    {
        if ($this->driver === null)
            throw new ConfigurationException("No database driver provided to format query");
        return $this->driver;
    }

    public function generateAlias(Clause $clause)
    {
        if ($clause instanceof FieldName)
        {
            $table = $clause->getTable();
            if (empty($table))
            {
                // When no table is defined, it means only one table is being
                // used in the query. Aliases will not be required
                return "";
            }

            $prefix = $table->getPrefix();
            $alias = $prefix . '_' . $clause->getField();
        }
        elseif ($clause instanceof SQLFunction)
        {
            $func = $clause->getFunction();
            $alias = strtolower($func);
        }
        else
            throw new ImplementationException("No alias generation implemented for: " . get_class($clause));

        $cnt = 1;
        $base_alias = $alias;
        while (isset($this->field_aliases[$alias]))
            $alias = $base_alias . (++$cnt);

        $this->field_aliases[$alias] = true;
        return $alias;
    }

    public function getSubScope(int $num = null)
    {
        // Resolve an existing scope
        if ($num !== null)
        {
            if (!isset($this->scopes[$num]))
                throw new QueryException("Invalid scope number: $num");
            return $this->scopes[$num];
        }

        $scope = new Parameters($this->driver);

        // Alias most fields
        $scope->params = &$this->params;
        $scope->param_types = &$this->param_types;
        $scope->column_counter = &$this->column_counter;
        $scope->table_counter = &$this->table_counter;
        $scope->scope_counter = &$this->scope_counter;
        $scope->scopes = &$this->scopes;

        // Assign a scope number
        $id = ++$this->scope_counter;
        $scope->parent_scope = $this;
        $scope->scope_id = $id;

        // Store the scope reference
        $this->scopes[$id] = $scope;

        return $scope;
    }
}

