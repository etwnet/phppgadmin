<?php

namespace PhpPgAdmin\Database;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Database\Connection\Postgres;

abstract class Action extends AbstractContext
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
