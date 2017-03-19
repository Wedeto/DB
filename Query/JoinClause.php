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

class JoinClause extends Clause
{
    protected $table;
    protected $condition;
    protected $type;

    protected static $valid_types = array(
        'LEFT' => 'LEFT OUTER',
        'RIGHT' => 'RIGHT OUTER',
        'FULL' => 'FULL OUTER',
        'INNER' => 'INNER',
        'CROSS' => 'CROSS'
    );

    public function __construct(string $type, $table, Expression $expression)
    {
        if (!array_key_exists($type, self::$valid_types))
            throw new \InvalidArgumentException("Invalid join type: " . \WASP\str($type));

        $this->type = $type;
        if (is_string($table))
        {
            $this->table = new SourceTableClause($table);
        }
        elseif ($table instanceof SourceTableClause)
        {
            $this->table = $table;
        }
        elseif ($table instanceof TableClause)
        {
            $this->table = new SourceTableClause($table->getTable());
        }
        else
        {
            throw new \DomainException("Invalid table type: " . \WASP\str($table));
        }

        $this->condition = $expression;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getCondition()
    {
        return $this->condition;
    }

    public function getType()
    {
        return $this->type;
    }
}

