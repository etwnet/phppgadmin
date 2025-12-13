<?php

namespace PhpPgAdmin\Database;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Database\Postgres;

abstract class AbstractActions extends AbstractContext
{
    /**
     * @var Postgres
     */
    protected $connection;

    public function __construct(Postgres $connection = null)
    {
        $this->connection = $connection ?? $this->postgres();
    }
}
