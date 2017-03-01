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

namespace WASP\DB\SQL;

use WASP\DB\Driver\Driver;

class Parameters
{
    protected $params = array();
    protected $tables = array();
    protected $column_counter = 0;
    protected $table_counter = 0;

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

    public function getNextKey()
    {
        return "c" . $this->column_counter++;
    }

    public function getParameters()
    {
        return $this->params;
    }

    public function getNextTableKey()
    {
        return "t" . $this->table_counter++;
    }

    public function addTable(string $table)
    {
        $key = $this->getNextTableKey();
        $this->table[$table] = $key;
        return $key;
    }

    public function getTable(string $table)
    {
        return $this->tables[$table];
    }
}

