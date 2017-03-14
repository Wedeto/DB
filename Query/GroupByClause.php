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

class GroupByClause extends Clause
{
    protected $groups;
    protected $having;

    public function __construct(...$conditions)
    {
        if (count($conditions) === 0)
            throw new \InvalidArgumentException("Specify at least one group by condition");

        $conditions = \WASP\flatten_array($conditions);
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
                throw new \InvalidArgumentException(
                    "Invalid parameter: " . \WASP\str($condition)
                );
            }
        }
    }

    public function setHaving(HavingClause $having)
    {
        $this->having = $having;
    }

    public function addGroup(Expression $expression)
    {
        $this->groups[] = $expression;
        return $this;
    }

    public function getGroups()
    {
        return $this->conditions;
    }

    public function getHaving()
    {
        return $this->having;
    }
}
