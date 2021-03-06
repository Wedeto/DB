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

namespace Wedeto\DB\Schema;

use Wedeto\Util\Dictionary;
use Wedeto\Util\DI\DI;
use Wedeto\Util\Cache;
use Wedeto\Util\Cache\Manager as CacheManager;
use Wedeto\DB\Exception\DBException;
use Wedeto\DB\Driver\Driver;

class SchemaException extends \Exception implements DBException
{}

class Schema
{
    protected $name;
    protected $tables;
    protected $db;

    /**
     * Create the schema, providing a name and specifying whether to use the cache or not.
     *
     * @param string $schema_name A name for the schema
     * @param bool $use_cache True to load the cache from disk, false to use a Dictionary for storage
     */
    public function __construct(string $schema_name, bool $use_cache)
    {
        $this->name = $schema_name;
        if ($use_cache)
            $this->loadCache();
        else
            $this->tables = new Dictionary;
    }
    
    /**
     * Set the database driver that can be used to obtain schemas
     * @param Wedeto\DB\Driver\Driver The database driver
     * @return Wedeto\DB\Schema\Schema Provides fluent interface
     */
    public function setDBDriver(Driver $drv)
    {
        $this->db = $drv;
        return $this;
    }

    /**
     * @return Wedeto\DB\Driver\Driver the database driver
     */
    public function getDBDriver()
    {
        return $this->db;
    }

    /**
     * Load the cache containing table definitions
     */
    public function loadCache()
    {
        if (empty($this->name))
            throw new SchemaException("Please provide a name for the schema when using the cache");

        $cachemgr = CacheManager::getInstance();
        $this->tables = $cachemgr->getCache('dbschema_' . $this->name);
    }

    /**
     * Clear the cache.
     * @return Schema provides fluent interface
     */
    public function clearCache()
    {
        $this->tables->set('tables', []);
        return $this;
    }
    
    /**
     * Whether any tables exist in the database
     */
    public function isEmpty()
    {
        return count($this->tables['tables']) == 0;
    }

    /**
     * Get a table definition from the schema
     */
    public function getTable($table_name)
    {
        if (!$this->tables->has('tables', $table_name))
        {
            if ($this->db !== null)
            {
                $table = $this->db->loadTable($table_name);
                $this->tables->set('tables', $table_name, $table);
            }
            else
                throw new SchemaException("Table $table not ofund");
        }

        return $this->tables->get('tables', $table_name);
    }

    /**
     * Add a table to the schema
     *
     * @return Wedeto\DB\Schema\Schema Provides fluent interface
     */
    public function putTable(Table $table)
    {
        $this->tables->set('tables', $table->getName(), $table);
        return $this;
    }

    /**
     * Remove a schema from the table definition
     * @param string|Table The table to remove
     * @return Wedeto\DB\Schema\Schema Provides fluent interface
     */
    public function removeTable($table)
    {
        if ($table instanceof Table)
            $table = $table->getName();
        $this->tables->set('tables', $table, null);
        return $this;
    }

    /**
     * When cloning the schema, we need to make sure the clone is not using the
     * Cache anymore, and we need to create a clone of all tables so they are
     * not co-dependent anymore.
     */
    public function __clone()
    {
        $tables = $this->tables->getAll();
        $this->tables = new Dictionary;
        foreach ($tables as $name => $table)
            $this->tables[$name] = clone $table;
    }
}
