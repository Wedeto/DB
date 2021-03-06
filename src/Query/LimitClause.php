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

class LimitClause extends Clause
{
    protected $number;

    public function __construct($value)
    {
        if (is_int($value))
        {
            $this->number = new ConstantValue($value, \PDO::PARAM_INT);
        }
        elseif ($value instanceof ConstantValue)
        {
            $this->number = $value;
            $this->number->setParameterType(\PDO::PARAM_INT);
        }
        else
            throw new \InvalidArgumentException("Invalid value for limit: " . WF::str($value));
    }

    public function getLimit()
    {
        return $this->number;
    }

    /**
     * Write a LIMIT clause to SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param bool $inner_caluse Unused
     * @return string The generated SQL
     */
    public function toSQL(Parameters $params, bool $inner_clause)
    {
        return "LIMIT " . $params->getDriver()->toSQL($params, $this->getLimit());
    }

}

