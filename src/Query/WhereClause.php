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

class WhereClause extends Clause
{
    protected $operand;

    public function __construct($operand)
    {
        if ($operand instanceof Expression)
            $this->operand = $operand; 
        elseif (is_string($operand))
            $this->operand = new CustomSQL($operand);
        elseif (is_array($operand))
            $this->initFromArray($operand);
        else
            throw new \InvalidArgumentException("Invalid operand: " . WF::str($operand));
    }

    public function getOperand()
    {
        return $this->operand;
    }

    protected function initFromArray(array $where)
    {
        $keys = array_keys($where);
        $first = array_shift($keys);
        $lhs = new ComparisonOperator("=", $first, $where[$first]);

        foreach ($keys as $key)
        {
            $rhs = new ComparisonOperator("=", $key, $where[$key]);
            $lhs = new BooleanOperator("AND", $lhs, $rhs);
        }

        $this->operand = $lhs;
    }
}

