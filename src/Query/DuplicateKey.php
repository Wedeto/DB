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

use DomainException;

use Wedeto\Util\Functions as WF;
use Wedeto\DB\Driver\PGSQL;
use Wedeto\DB\Driver\MySQL;
use Wedeto\DB\Exception\ImplementationException;

class DuplicateKey extends Clause
{
    protected $fields = array();
    protected $updates = array();

    public function __construct($field, ...$updates)
    {
        $this->addConflictingField($field);
        $updates = WF::flatten_array($updates);
        foreach ($updates as $up)
            $this->addUpdate($up);
    }

    public function addConflictingField($field)
    {
        if (is_array($field))
        {
            foreach ($field as $f)
                $this->addConflictingField($f);
            return;
        }

        if (!($field instanceof FieldName))
            $field = new FieldName($field);

        $this->fields[] = $field;
    }

    public function getConflictingFields()
    {
        return $this->fields;
    }

    public function addUpdate(UpdateField $update)
    {
        $this->updates[] = $update;
    }

    public function getUpdates()
    {
        return $this->updates;
    }

    public function toSQL(Parameters $params, bool $inner_clause)
    {
        $drv = $params->getDriver();
        if (get_class($drv) === \Wedeto\DB\Driver\PGSQL::class)
        {
            $query = array("ON CONFLICT");

            $conflicts = $this->getConflictingFields();
            $parts = array();
            foreach ($conflicts as $c)
                $parts[] = $drv->toSQL($params, $c, false);

            $query[] = "(" . implode(', ', $parts) . ")";
            $query[] = "DO UPDATE";
            $query[] = "SET";

            $updates = $this->getUpdates();
            $parts = array();
            foreach ($updates as $up)
                $parts[] = $drv->toSQL($params, $up);

            $query[] = implode(", " , $parts);

            return implode(" ", $query);
        }
        elseif (get_class($drv) === \Wedeto\DB\Driver\MySQL::class)
        {
            $query = array("ON DUPLICATE KEY");

            // MySQL doesn't care if you know which fields conflict
            $query[] = "UPDATE";

            $updates = $this->getUpdates();
            $parts = array();
            foreach ($updates as $up)
                $parts[] = $drv->toSQL($params, $up);

            $query[] = implode(", " , $parts);

            return implode(" ", $query);
        }
        else
        {
            throw new ImplementationException("On duplicate key not implemented for: " . get_class($drv));
        }
    }
}
