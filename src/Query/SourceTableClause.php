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

use Wedeto\DB\DB;

class SourceTableClause extends TableClause
{
    public function __construct($table, string $alias = "")
    {
        if (is_string($table))
            $this->setTable($table);
        elseif ($table instanceof TableClause)
            $this->setTable($table->getTable());

        $this->setAlias($alias);
    }

    public function toSQL(Parameters $params, bool $inner_clause)
    {
        $name = $this->getTable();
        $alias = $this->getAlias();
        $prefix_disabled = $this->getDisablePrefixing();

        $drv = $params->getDriver();
        $sql = $prefix_disabled ? $drv->identQuote($name) : $drv->getName($name);
        if (!empty($alias))
        {
            $params->registerTable($name, $alias);
            $sql .= ' AS ' . $drv->identQuote($alias);
        }
        else
            $params->registerTable($name, null);

        return $sql;
    }
}
