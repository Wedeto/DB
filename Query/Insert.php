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

use DomainException;
use WASP\DB\DAO;

class Insert extends Query
{
    protected $id_field;
    protected $fields;
    protected $table;
    protected $values;
    protected $on_duplicate = null;

    protected $inserted_id = null;

    public function __construct($table, $record, string $idfield = "")
    {
        if (!($table instanceof TableClause))
            $table = new TableClause($table);

        if ($record instanceof DAO)
            $record = $record->getRecord();
        else
            $record = \WASP\to_array($record);

        $this->table = $table;
        $this->fields = array();
        $this->values = array();

        foreach ($record as $key => $value)
        {
            $this->fields[] = new FieldName($key);
            if (!($value instanceof ConstantValue))
                $value = new ConstantValue($value);
            $this->values[] = $value;
        }

        if (!empty($idfield))
            $this->setIDField($idfield);
    }

    public function updateOnDuplicateKey(...$index_fields)
    {
        $updates = array();
        foreach ($this->fields as $idx => $fld)
        {
            $name = $fld->getField();
            $value = $this->values[$idx];
            if (!in_array($name, $index_fields, true))
                $updates[] = new UpdateField($fld, $value);
        }
        $this->on_duplicate = new DuplicateKey($index_fields, $updates);
        return true;
    }

    public function setIDField(string $id_field)
    {
        foreach ($this->fields as $fld)
        {
            if ($fld->getField() === $id_field)
                throw new \InvalidArgumentException("Refusing to insert with predefined ID");
        }

        $this->id_field = $id_field;
        return $this;
    }

    public function getIDField()
    {
        return $this->id_field;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function getValues()
    {
        return $this->values;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function setInsertId($id)
    {
        $this->inserted_id = $id;
        return $this;
    }

    public function getInsertId()
    {
        return $this->inserted_id;
    }

    public function getOnDuplicate()
    {
        return $this->on_duplicate;
    }
}
