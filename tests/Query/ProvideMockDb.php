<?php

namespace Wedeto\DB;

class MockDB extends DB
{
    public function __construct($type = "PGSQL")
    {
        $this->driver = $type === "PGSQL" ? new \Wedeto\DB\Driver\PGSQL($this) : new \Wedeto\DB\Driver\MySQL($this);
    }
}
