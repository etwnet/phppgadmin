<?php

namespace PhpPgAdmin\Database;

use PhpPgAdmin\Database\Connection\Postgres;

abstract class Action
{
    /**
     * @var Postgres
     */
    protected $connection;

    public function __construct(Postgres $connection)
    {
        $this->connection = $connection;
    }
}
