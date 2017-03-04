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

class GetClause extends Clause
{
    protected $expression;
    protected $alias;

    public function __construct($exp, $alias)
    {
        $this->expression = $this->toExpression($exp, false);
        $this->alias = $alias;
    }

    public function registerTables(Parameters $parameters)
    {
        $this->expression->registerTables($parameters);
    }

    public function toSQL(Parameters $parameters, bool $enclose)
    {
        $sql = $this->expression->toSQL($parameters, true);
        if (empty($this->alias))
        {
            if ($this->expression instanceof FieldExpression)
            {
                $table = $this->expression->getTable();
                if ($table !== null)
                {
                    $prefix = $table->getPrefix();
                    $this->alias = $prefix . '_' . $this->expression->getField();
                }
            }
            elseif ($this->expression instanceof FunctionExpression)
            {
                $func = $this->expression->getFunction();
                $this->alias = strtolower($func);
            }
        }

        if ($this->alias)
            return $sql . ' AS ' . $parameters->getDB()->identQuote($this->alias);
        else
            return $sql;
    }
}
