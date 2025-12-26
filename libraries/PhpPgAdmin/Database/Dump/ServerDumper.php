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

        // Ensure psql halts on error for restores
        $this->write("\\set ON_ERROR_STOP on\n\n");
        $this->write("SET client_encoding = 'UTF8';\n\n");

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

        // Build list of databases to dump first so we can detect multi-db mode
        $toDump = [];
        while ($databases && !$databases->EOF) {
            $dbName = $databases->fields['datname'];

            // If specific databases are selected, only consider those
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

            $toDump[] = $dbName;
            $databases->moveNext();
        }

        $dbDumper = $this->createSubDumper('database');
        $multiDbMode = count($toDump) > 1;

        foreach ($toDump as $dbName) {
            // If exporting multiple databases, emit a clear marker and a psql \connect command
            if ($multiDbMode) {
                $this->write("--\n");
                $this->write("-- Dumping database: \"{$dbName}\"\n");
                $this->write("--\n");
                $this->write("\\connect \"" . addslashes($dbName) . "\"\n");
                // Emit encoding for psql and SQL fallback so restores work with any client
                $this->write("\\encoding UTF8\n");
                $this->write("SET client_encoding = 'UTF8';\n");                // Session and restore-friendly settings (align with pg_dump)
                $this->write("SET statement_timeout = 0;\n");
                $this->write("SET lock_timeout = 0;\n");
                $this->write("SET idle_in_transaction_session_timeout = 0;\n");
                $this->write("SET transaction_timeout = 0;\n");
                $this->write("SET standard_conforming_strings = on;\n");
                $this->write("SELECT pg_catalog.set_config('search_path', '', false);\n");
                $this->write("SET check_function_bodies = false;\n");
                $this->write("SET xmloption = content;\n");
                $this->write("SET client_min_messages = warning;\n");
                $this->write("SET row_security = off;\n");                // Set session_replication_role to replica for the whole DB restore
                $this->write("SET session_replication_role = 'replica';\n\n");

                // Suppress DatabaseDumper's own \c, preliminaries and replication role toggles to avoid duplicates
                $dbOptions = $options;
                $dbOptions['suppress_connect'] = true;
                $dbOptions['suppress_preliminaries'] = true;
                $dbOptions['suppress_replication_role'] = true;
            } else {
                $dbOptions = $options;
            }

            $dbDumper->dump('database', ['database' => $dbName], $dbOptions);

            // After dumping this database, reset session_replication_role to origin
            if ($multiDbMode) {
                $this->write("SET session_replication_role = 'origin';\n\n");
            }
        }
    }
}
