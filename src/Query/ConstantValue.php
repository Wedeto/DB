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

use PDO;
use InvalidArgumentException;

class ConstantValue extends Expression
{
    protected $value;
    protected $parameter_type;
    protected $target_key = null;
    protected $parameters = null;
    protected $formatter = null;

    public function __construct($value, $type = PDO::PARAM_STR)
    {
        $this->setParameterType($type);
        $this->setValue($value);
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getKey()
    {
        return $this->target_key;
    }

    public function getParameterType()
    {
        return $this->parameter_type;
    }

    public function setParameterType($type)
    {
        $this->parameter_type = $type;
        return $this;
    }

    public function setValue($value)
    {
        if ($value instanceof \IntlCalendar)
            $value = $value->toDateTime();

        if ($value instanceof \DateTimeInterface)
            $value = $value->format(\DateTime::ATOM);

        if (is_resource($value))
        {
            if ($this->parameter_type !== PDO::PARAM_LOB)
                throw new QueryException("A resource can only be used for a PARAM_LOB type parameter");
        }
        elseif (!is_scalar($value) && $value !== null)
            throw new InvalidArgumentException("Invalid data type for constant: " . WF::str($value));

        $this->value = $value;
        $this->update();
        return $this;
    }

    public function bind(Parameters $params, string $key, callable $formatter = null)
    {
        $this->parameters = $params;
        if (!empty($formatter))
            $this->formatter = $formatter;
        $this->target_key = $key;
        $this->update();
    }

    protected function update()
    {
        $value = empty($this->formatter) ? $this->value : ($this->formatter)($this->value);
        if (!empty($this->parameters))
            $this->parameters->set($this->target_key, $value, $this->parameter_type);
    }

    /**
     * Write a constant as SQL query syntax
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
                $key = null;
            }
        }

        if ($key === null)
        {
            $val = $this->getValue();
            if (is_bool($val))
                $val = $val ? 1 : 0;
            $key = $params->assign($val, $this->getParameterType());
        }
        $this->bind($params, $key, null);

        return ':' . $key;
    }

}

