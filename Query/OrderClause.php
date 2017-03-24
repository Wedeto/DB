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

use WASP\Util\Functions as WF;

class OrderClause extends Clause
{
    protected $clauses = array();

    public function __construct(...$args)
    {
        $args = WF::flatten_array($args);

        foreach ($args as $k => $arg)
        {
            if (is_array($arg))
                $this->initFromArray($arg);
            elseif (is_string($arg) && is_numeric($k))
                $this->addClause(new Direction("ASC", $arg)); 
            elseif (is_string($arg) || $arg instanceof Direction)
                $this->addClause($arg);
            else
                throw new \InvalidArgumentException("Invalid order: " . WF::str($arg));
        }
    }

    public function addClause($clause)
    {
        if (is_string($clause))
            $clause = new CustomSQL($clause);
        if (!($clause instanceof Clause))
            throw new \InvalidArgumentException("No clause provided to order by");

        $this->clauses[] = $clause;
    }

    protected function initFromArray(array $clauses)
    {
        foreach ($clauses as $k => $v)
        {
            if (is_numeric($k))
                $this->addClause(new Direction("ASC", $v));
            else
                $this->addClause(new Direction($v, $k));
        }
    }

    public function getClauses()
    {
        return $this->clauses;
    }
}

