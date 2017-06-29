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

namespace Wedeto\DB\Schema\Column;

use Wedeto\Util\Functions as WF;
use Wedeto\DB\Exception\InvalidValueException;

class TInt extends Column
{
    public function __construct(string $name, $default = null, bool $nullable = false)
    {
        parent::__construct($name, Column::INT, $default, $nullable);
        $this->setNumericPrecision(10);
    }

    public function validate($value)
    {
        parent::validate();

        if ($value === null)
            return true;

        if (!WF::is_int_val($value))
            throw new InvalidValueException("Invalid value for " . $this->type . ": " . WF::str($value));

        $precision = $this->numeric_precision;
        $str = (string)$value;
        if (strlen($str) > $precision)
            throw new InvalidValueException("Value out of range for " . $this->type . ": " . $value);

        return true;
    }
}
