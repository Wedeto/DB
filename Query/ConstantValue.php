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

class ConstantValue extends Expression
{
    protected $value;
    protected $target_key = null;
    protected $parameters = null;
    protected $formatter = null;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function isNull()
    {
        return $this->value === null;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getKey()
    {
        return $this->target_key;
    }

    public function setValue($value)
    {
        $this->value = $value;
        $this->update();
        return $this;
    }

    public function bind(Parameters $params, string $key, $formatter)
    {
        $this->parameters = $params;
        if (!empty($formatter))
        {
            if (!is_callable($formatter))
                throw new \InvalidArgumentException("Formatter must be callable");
            $this->formatter = $formatter;
        }
        $this->target_key = $key;
        $this->update();
    }

    protected function update()
    {
        $value = empty($this->formatter) ? $this->value : ($this->formatter)($this->value);
        $this->parameters->set($this->target_key, $value);
    }

    public function __debugInfo()
    {
        return array(
            'value' => $this->value,
            'target_key' => $this->target_key,
            'parameters' => ($this->parameters === null) ? null : get_class($this->parameters) . '#' . spl_object_hash($this->parameters),
            'formatter' => $this->formatter
        );
    }
}

