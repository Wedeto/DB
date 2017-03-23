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

class Update extends Query
{
    protected $table;
    protected $joins = array();
    protected $updates = array();
    protected $where;

    public function __construct(...$params)
    {
        foreach ($params as $p)
            $this->add($p);
    }

    public function add(Clause $clause)
    {
        if ($clause instanceof WhereClause)
            $this->where = $clause;
        elseif ($clause instanceof TableClause)
            $this->setTable($clause);
        elseif ($clause instanceof JoinClause)
            $this->joins[] = $clause;
        elseif ($clause instanceof UpdateField)
            $this->updates[] = $clause;
        else
            throw new \InvalidArgumentException("Unknown clause: " . \WASP\str(get_class($clause)));
    }

    public function setTable($table)
    {
        if (!($table instanceof SourceTableClause))
        {
            if ($table instanceof TableClause)
                $table = new SourceTableClause($table->getTable());
            elseif (is_string($table))
                $table = new SourceTableClause($table);
            else
                throw new \InvalidArgumentException("Invalid table: " . \WASP\str($table));
        }

        $this->table = $table;
    }

    public function where($condition)
    {
        $this->where = new WhereClause($condition);
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getWhere()
    {
        return $this->where;
    }

    public function getUpdates()
    {
        return $this->updates;
    }

    public function getJoins()
    {
        return $this->joins;
    }
}
