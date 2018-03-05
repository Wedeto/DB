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

namespace Wedeto\DB\Driver;

use Wedeto\DB\DB;

use Wedeto\DB\Exception\InvalidTypeException;

use Wedeto\DB\Schema\Table;
use Wedeto\DB\Schema\Index;
use Wedeto\DB\Schema\ForeignKey;
use Wedeto\DB\Schema\Column\Column;

use Wedeto\DB\Query;
use Wedeto\DB\Query\Parameters;

use PDO;
use PDOException;

abstract class Driver
{
    protected $db;
    protected $table_prefix = "";

    protected $host;
    protected $dbname;
    protected $schema;

    protected $mapping = array();
    protected $reverse_mapping = array();
    protected $iquotechar = null;

    public function __construct($db)
    {
        if (!($db instanceof PDO || $db instanceof DB))
            throw new InvalidTypeException("The driver needs a DB or PDO object to work with");

        $this->db = $db;
    }

    /**
     * Set a prefix that will be prepended to all table names
     * and all key names (indexes, foreign keys etc).
     * @param $prefix string The prefix string
     * @return $Driver Provides fluent interface
     */
    public function setTablePrefix(string $prefix)
    {
        $this->table_prefix = $prefix;
        return $this;
    }

    /**
     * @return string The table prefix
     */
    public function getTablePrefix()
    {
        return $this->table_prefix;
    }

    /**
     * Quote the name of an identity
     * @param $name string The name to quote
     * @return string The quoted name
     */
    public function identQuote(string $name)
    {
        return $this->iquotechar . str_replace($this->iquotechar, $this->iquotechar . $this->iquotechar, $name) . $this->iquotechar;
    }

    /**
     * Return the identity name quoted and prefixed with the configured prefix.
     *
     * @param $entity mixed A string with the name or a Table, Index or ForeignKey
     *                     object with a getName method.
     * @return string The quoted, prefixed name
     */
    public function getName($entity, $quote = true)
    {
        if (is_object($entity))
            $entity = $entity->getName();
        if (!is_string($entity))
            throw new InvalidTypeException("Provide a string or a object with a getName method");
        $entity = $this->table_prefix . $entity;
        return $quote ? $this->identQuote($entity) : $entity;
    }

    public function getDatabaseName()
    {
        return $this->dbname;
    }

    public function getSchemaName()
    {
        return $this->schema;
    }

    public function getHostname()
    {
        return $this->host;
    }

    public function stripPrefix($name)
    {
        // Strip the prefix away
        if (!empty($this->table_prefix) && substr($name, 0, strlen($this->table_prefix)) === $this->table_prefix)
            $name = substr($name, strlen($this->table_prefix));
        return $name;
    }

    public function equivalentTypes(Column $c1, Column $c2)
    {
        $t1 = $c1->getType();
        $t2 = $c2->getType();

        $m1 = $this->mapping[$t1];
        $m2 = $this->mapping[$t2];

        if ($m1 !== $m2)
            return false;

        if ($c1->getMaxLength() !== $c2->getMaxLength())
            return false;

        if ($c1->getNumericPrecision() !== $c2->getNumericPrecision())
            return false;

        if ($c1->getNumericScale() !== $c2->getNumericScale())
            return false;

        if ($t1 === Column::ENUM)
        {
            $vals1 = $c1->getEnumValues();
            $vals2 = $c2->getEnumValues();
            if (count($vals1) !== count($vals2))
                return false;

            $diff = array_diff($vals1, $vals2);
            if (count($diff) > 0)
                return false;
        }
        return true;
    }

    // PDO
    abstract public function generateDSN(array $config);

    // CRUD
    abstract public function select(Query\Select $query);
    abstract public function update(Query\Update $query);
    abstract public function insert(Query\Insert $query, $id_field = null);
    abstract public function delete(Query\Delete $query);

    /**
     * Remove all rows from the table
     *
     * @param $table mixed The table from which to remove
     * @return Driver Provides fluent interface
     */
    public function truncateTable($table)
    {
        $query = "TRUNCATE " . $this->getName($table->getName());
        $this->db->exec($query);
        return $this;
    }

    // Table administration
    abstract public function createTable(Table $table);
    abstract public function createIndex(Table $table, Index $idx);
    abstract public function dropIndex(Table $table, Index $idx);
    abstract public function createForeignKey(Table $table, ForeignKey $fk);
    abstract public function dropForeignKey(Table $table, ForeignKey $fk);
    abstract public function createSerial(Table $table, Column $column);
    abstract public function dropSerial(Table $table, Column $column);
    abstract public function addColumn(Table $table, Column $column);
    abstract public function removeColumn(Table $table, Column $column);
    abstract public function getColumnDefinition(Column $col);
    abstract public function dropTable($table, $safe = false);

    // Importing / generating table definitions
    abstract public function loadTable($table_name);
    abstract public function getColumns($table_name);
    abstract public function getConstraints($table_name);

    /**
     * Explodes a string on occurences of , while
     * parsing functions properly. Each opening brace should be
     * accompanied by a closing brace, and only split the string
     * at the top-level, so that
     * "max(a, b),c" is split into array("max(a, b)", "c") rather than
     * array("max(a,", "b)", "c");
     * 
     * @param $str string The expression to parse
     * @return array The split string
     */
    public static function explodeFunc($str)
    {
        $parts = array();

        $buf = "";
        $brace_count = 0;
        $in_quote = 0;
        for ($i = 0; $i < strlen($str); ++$i)
        {
            $ch = substr($str, $i, 1);
            if ($ch === "'")
            {
                $in_quote = !$in_quote;
            }
            elseif ($ch === '(' && !$in_quote)
            {
                ++$brace_count;
            }
            elseif ($ch === ')' && !$in_quote)
            {
                --$brace_count;
            }
            elseif ($ch === ',' && !$in_quote && $brace_count === 0)
            {
                $parts[] = $buf;
                $buf = "";
                continue;
            }
            $buf .= $ch;
        }
        if (!empty($buf))
            $parts[] = $buf;
        return array_map('trim', $parts);
    }

    /**************************************************************************
     ************************** QUERY BUILDER *********************************
     **************************************************************************/

    /**
     * Write an query clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Clause $clause The clause to write
     * @param bool $inner_clause Whether this is a inner or outer clause. An
     *                           inner clause will be wrapped in braces when
     *                           it's a binary operator.
     * @return string The generated SQL
     */
    public function toSQL(Parameters $params, Query\Clause $clause, bool $inner_clause = false)
    {
        $params->setDriver($this);
        return $clause->toSQL($params, $inner_clause);
    }

    abstract public function formatArray(array $values);
    abstract public function matchMultipleValues(Query\FieldName $field, Query\ConstantArray $list);
}
