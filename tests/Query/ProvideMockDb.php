<?php

namespace Wedeto\DB;

class MockDB extends DB
{
    public function __construct()
    {
        $this->driver = new \Wedeto\DB\Driver\PGSQL($this);
    }
}
