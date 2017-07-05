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

use Wedeto\Util\Functions as WF;

use Wedeto\DB\Exception\QueryException;
use Wedeto\DB\Exception\InvalidTypeException;

class GroupByClause extends Clause
{
    protected $groups;
    protected $having;

    public function __construct(...$conditions)
    {
        if (count($conditions) === 0)
            throw new QueryException("Specify at least one group by condition");

        $conditions = WF::flatten_array($conditions);
        foreach ($conditions as $condition)
        {
            if ($condition instanceof HavingClause)
            {
                $this->setHaving($condition);
            }
            elseif (is_string($condition) || $condition instanceof Expression)
            {
                $this->addGroup(self::toExpression($condition, false));
            }
            else
            {
                throw new InvalidTypeException(
                    "Invalid parameter: " . WF::str($condition)
                );
            }
        }
    }

    public function setHaving(HavingClause $having)
    {
        $this->having = $having;
        return $this;
    }

    public function addGroup(Expression $expression)
    {
        $this->groups[] = $expression;
        return $this;
    }

    public function getGroups()
    {
        return $this->groups;
    }

    public function getHaving()
    {
        return $this->having;
    }

    /**
     * Write a GROUPBY clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param bool $inner_clause Unused
     * @return string The generated SQL
     */
    public function toSQL(Parameters $params, bool $inner_clause)
    {
        $groups = $this->getGroups();
        $having = $this->getHaving();

        if (count($groups) === 0)
            throw new QueryException("No groups in GROUP BY clause");
            
        $drv = $params->getDriver();

        $parts = array();
        foreach ($groups as $group)
        {
            $parts[] = $drv->toSQL($params, $group);
        }
        
        $having = !empty($having) ? ' ' . $drv->toSQL($params, $having) : "";

        return "GROUP BY " . implode(", ", $parts) . $having;
    }
}
