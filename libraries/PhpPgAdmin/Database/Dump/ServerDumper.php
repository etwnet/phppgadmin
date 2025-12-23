<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\DatabaseActions;

/**
 * Orchestrator dumper for a PostgreSQL server (cluster).
 */
class ServerDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $this->writeHeader("Server Cluster");

        // 1. Roles
        $roleDumper = DumpFactory::create('role', $this->connection);
        $roleDumper->dump('role', [], $options);

        // 2. Tablespaces
        $tablespaceDumper = DumpFactory::create('tablespace', $this->connection);
        $tablespaceDumper->dump('tablespace', [], $options);

        // 3. Databases
        $databaseActions = new DatabaseActions($this->connection);
        $databases = $databaseActions->getDatabases();

        $dbDumper = DumpFactory::create('database', $this->connection);
        while ($databases && !$databases->EOF) {
            $dbName = $databases->fields['datname'];

            // Skip template databases unless requested
            if (empty($options['all_databases']) && strpos($dbName, 'template') === 0) {
                $databases->moveNext();
                continue;
            }

            $dbDumper->dump('database', ['database' => $dbName], $options);
            $databases->moveNext();
        }
    }
}
