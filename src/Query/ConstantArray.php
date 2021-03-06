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

use InvalidArgumentException;

use Wedeto\Util\Functions as WF;

class ConstantArray extends ConstantValue
{
    public function __construct($value, ...$values)
    {
        $this->setParameterType(\PDO::PARAM_STR);
        $this->setValue(func_get_args());
    }

    public function setValue($value)
    {
        if (!WF::is_array_like($value))
            throw new InvalidArgumentException("Cannot assign non-array to ConstantArray");

        $args = WF::flatten_array(func_get_args());
        $this->value = array();
        foreach ($args as $arg)
        {
            if (!is_scalar($arg))
                throw new InvalidArgumentException("Not a scalar: " . WF::str($arg));
            $this->value[] = $arg;
        }

        if (!empty($this->formatter))
            $this->update();
    }

    /**
     * Write a constant array clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @return string The generated SQL
     */
    public function toSQL(Parameters $params, bool $inner_clause)
    {
        if ($key = $this->getKey())
        {
            try
            {
                $params->get($key);
            }
            catch (\OutOfRangeException $e)
            {
                // Not a valid key, replace
                $key = null;
            }
        }

        if (!$key)
            $key = $params->assign(null);

        // Rebind, to be sure
        $this->bind($params, $key, array($params->getDriver(), 'formatArray'));
        return ':' . $key;
    }

}
