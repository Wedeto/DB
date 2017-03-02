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

use InvalidArgumentException;

class ComparisonOperator extends Expression
{
    protected static $valid_operators = array('<', '>', '<=', '>=', '=', '!=', 'LIKE', 'ILIKE');

    public function __construct($op, $lhs, $rhs)
    {
        if (!in_array($op, self::$valid_operators))
            throw new InvalidArgumentException($op);

        $this->op = $op;
        $this->lhs = $this->toExpression($lhs, false);
        $this->rhs = $this->toExpression($rhs, true);
        if ($this->rhs->isNull())
        {
            if ($this->op === "=")
                $this->op = "IS";
            elseif ($this->op === "!=")
                $this->op = "IS NOT";
        }
    }

    public function registerTables(Parameters $parameters)
    {
        $this->lhs->registerTables($parameters);
        $this->rhs->registerTables($parameters);
    }

    public function toSQL(Parameters $parameters)
    {
        return '(' . $this->lhs->toSQL($parameters) . ' ' . $this->op . ' ' . $this->rhs->toSQL($parameters) . ')';
    }
}

