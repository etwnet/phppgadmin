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

        // 1. Roles (if enabled)
        if (!isset($options['export_roles']) || $options['export_roles']) {
            $roleDumper = $this->createSubDumper('role');
            $roleDumper->dump('role', [], $options);
        }

        // 2. Tablespaces (if enabled)
        if (!isset($options['export_tablespaces']) || $options['export_tablespaces']) {
            $tablespaceDumper = $this->createSubDumper('tablespace');
            $tablespaceDumper->dump('tablespace', [], $options);
        }

        // 3. Databases
        $databaseActions = new DatabaseActions($this->connection);
        $databases = $databaseActions->getDatabases();

        // Get list of selected databases (if any)
        $selectedDatabases = !empty($options['databases']) ? $options['databases'] : [];

        $dbDumper = $this->createSubDumper('database');
        while ($databases && !$databases->EOF) {
            $dbName = $databases->fields['datname'];

            // If specific databases are selected, only dump those
            if (!empty($selectedDatabases)) {
                if (!in_array($dbName, $selectedDatabases)) {
                    $databases->moveNext();
                    continue;
                }
            } else {
                // Default behavior: skip template databases unless requested
                if (empty($options['all_databases']) && strpos($dbName, 'template') === 0) {
                    $databases->moveNext();
                    continue;
                }
            }

            $dbDumper->dump('database', ['database' => $dbName], $options);
            $databases->moveNext();
        }
    }
}
