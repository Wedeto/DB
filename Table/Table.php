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

namespace WASP\DB\Table;

use WASP\DB\DBException;
use WASP\DB\Table\Column\Column;
use WASP\DB\Table\Index;
use WASP\DB\Table\ForeignKey;

use WASP\Debug\Logger;

class Table implements \Serializable, \JSONSerializable
{
    protected $name;
    protected $columns = array();
    protected $indexes = array();
    protected $foreign_keys = array();
    protected $primary = null;

    public function __construct($name)
    {
        if (is_array($name))
        {
            $this->initFromArray($name);
            return;
        }

        $this->name = $name;

        $duplicate = false;
        try
        {
            $tb = TableRepository::getTable($name);
            $duplicate = true;
        }
        catch (DBException $e)
        {
            // This is actually the wanted situation
        }

        if ($duplicate)
            throw new DBException("Duplicate table definition for {$name}");

        $args = func_get_args();
        array_shift($args); // Name
        foreach ($args as $arg)
        {
            if ($arg instanceof Column)
                $this->addColumn($arg);
            elseif ($arg instanceof Index)
                $this->addIndex($arg);
            elseif ($arg instanceof ForeignKey)
                $this->addForeignKey($arg);
            else
                throw new DBException("Invalid argument: " . Logger::str($arg));
        }

        TableRepository::putTable($this);
    }

    protected function initFromArray($data)
    {
        $name = $data['name'];
        $cols = $data['columns'];
        $fks = !empty($data['foreign_keys']) ? $data['foreign_keys'] : array();
        $indexes = !empty($data['indexes']) ? $data['indexes'] : array();

        $this->name = $name;
        foreach ($cols as $name => $col)
        {
            $col['column_name'] = $name;
            $this->addColumn(Column::fromArray($col));
        }

        foreach ($indexes as $idx)
            $this->addIndex(new Index($idx));

        foreach ($fks as $fk)
            $this->addForeignKey(new ForeignKey($fk));

        $this->validate();
    }

    public function getName()
    {
        return $this->name;
    }

    public function addColumn(Column $column)
    {
        if (isset($this->columns[$column->getName()]))
            throw new DBException("Duplicate column name '" . $column->getName() . "'");

        $this->columns[$column->getName()] = $column;
        $column->setTable($this);
        return $this;
    }

    public function getColumn($name)
    {
        if (isset($this->columns[$name]))
            return $this->columns[$name];
        throw new DBException("Unknown column: " . $name);
    }

    public function removeColumn(Column $column)
    {
        // Check if it exists
        if (!isset($this->columns[$column->getName()]))
            throw new DBException("Column is not part of this table");

        // Check if it's used in any index
        $remain = array();
        foreach ($this->indexes as $k => $idx)
        {
            foreach ($idx->getColumns() as $c)
            {
                if ($c->getName() === $column->getName())
                    throw new DBException("Cannot remove column that is in an index");
            }
        }


        // Check if it's used in any foreign key
        foreach ($this->foreign_keys as $k => $fk)
        {
            foreach ($fk->getColumns() as $c)
            {
                if ($c->getName() === $column->getName())
                    throw new DBException("Cannot remove column that is in a foreign key");
            }
        }

        // All well
        unset($this->columns[$column->getName()]);
        $column->setTable(null);
        return $this;
    }

    public function addForeignKey(ForeignKey $fk)
    {
        $fk->setTable($this);
        $this->foreign_keys[] = $fk;
        return $this;
    }

    public function getForeignKey($name)
    {
        foreach ($this->foreign_keys as $fk)
            if ($fk->getName() === $name)
                return $fk;
        throw new DBException("Unknown foreign key: " . $name);
    }

    public function removeForeignKey(ForeignKey $fkey)
    {
        foreach ($this->foreign_keys as $key => $fk)
        {
            if ($fk->getName() === $fkey->getName())
            {
                unset($this->foreign_keys[$key]);
                break;
            }
        }
        return $this;
    }

    public function addIndex(Index $idx)
    {
        $idx->setTable($this);
        if ($idx->getType() === Index::PRIMARY)
        {
            if ($this->primary !== null)
                throw new DBException("A table can have only one primary key");
            $this->primary = $idx;
        }

        $this->indexes[] = $idx;
        return $this;
    }

    public function getIndex($name)
    {
        foreach ($this->indexes as $idx)
            if ($idx->getName() === $name)
                return $idx;
        throw new DBException("Unknown index: " . $name);
    }

    public function removeIndex(Index $index)
    {
        if ($index->getType() === Index::PRIMARY)
        {
            if ($this->primary !== $index)
                throw new DBException("Cannot remove primary - it is not");
            $this->primary = null;
        }

        foreach ($this->indexes as $key => $idx)
        {
            if ($idx->getName() === $index->getName())
            {
                unset($this->indexes[$key]);
                break;
            }
        }
        return $this;
    }

    public function getColumns()
    {
        return $this->columns;
    }

    public function getIndexes()
    {
        return $this->indexes;
    }

    public function getForeignKeys()
    {
        return $this->foreign_keys;
    }

    public function toArray()
    {
        $arr = array(
            'name' => $this->name,
            'columns' => array(),
        );

        foreach ($this->columns as $column)
        {
            $column = $column->toArray();
            $arr['columns'][$column['column_name']] = $column;
        }

        if (count($this->indexes))
            foreach ($this->indexes as $index)
                $arr['indexes'][] = $index->toArray();

        if (count($this->foreign_keys))
            foreach ($this->foreign_keys as $fk)
                $arr['foreign_keys'][] = $fk->toArray();

        return $arr;
    }

    public function jsonSerialize()
    {
        return $this->toArray();
    }
    
    public function serialize()
    {
        return serialize($this->toArray());
    }

    public function unserialize($data)
    {
        $arr = unserialize($data);
        $this->initFromArray($arr);
    }

    public function validate()
    {
        $serial = false;
        foreach ($this->columns as $name => $col)
        {
            if ($col->getSerial())
            {
                if ($serial !== false)
                    throw new DBException("Duplicate serial column");
                $serial = $name;
            }
        }

        $pkey = false;
        foreach ($this->indexes as $idx)
        {
            $cols = $idx->getColumns();
            if ($idx->getType() === Index::PRIMARY && $serial)
            {
                if (count($cols) > 1 || $cols[0] !== $serial)
                    throw new DBException("The serial column must be the primary key");
                $pkey = true;
            }
            foreach ($cols as $col)
            {
                if (strpos($col, '(') !== false)
                    continue; // Parsing function expressions is out-of-scope

                if (($sp_pos = strpos($col, ' ')) !== false)
                {
                    // Modifiers may be present after the name of the column,
                    // such as ASC or DESC. Validating these is out of scope
                    $col = substr($col, 0, $sp_pos);
                }

                if (!isset($this->columns[$col]))
                    throw new DBException("Index {$idx->getName()} used unknown column {$col}");
            }
        }

        if ($serial && !$pkey)
            throw new DBException("The serial column must be the primary key");

        foreach ($this->foreign_keys as $fk)
        {
            $cols = $fk->getColumns();
            foreach ($cols as $col)
                if (!isset($this->columns[$col]))
                    throw new DBException("The foreign key {$fk->getName()} used unknown column {$col}");
        }

    }
}
