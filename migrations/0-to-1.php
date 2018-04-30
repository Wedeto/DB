<?php

use Wedeto\DB\Query\Builder AS QB;
use Wedeto\DB\Schema\Table;
use Wedeto\DB\Schema\Column;
use Wedeto\DB\Schema\Index;

$table = new Table(
    "db_version",
    new Column\Serial('id'),
    new Column\Varchar('module', 128),
    new Column\Integer('from_version'),
    new Column\Integer('to_version'),
    new Column\Datetime('migration_date'),
    new Column\Varchar('filename', 255),
    new Column\Varchar('md5sum', 32),
    new Index(Index::PRIMARY, 'id'),
    new Index(Index::INDEX, 'module', 'migration_date')
);

$db->getDriver()->createTable($table);
