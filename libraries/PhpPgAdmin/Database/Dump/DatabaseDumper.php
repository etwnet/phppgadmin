<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Connector;
use PhpPgAdmin\Database\Postgres;

/**
 * Orchestrator dumper for a PostgreSQL database.
 */
class DatabaseDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $database = $params['database'] ?? $this->connection->conn->database;
        if (!$database) {
            return;
        }

        $c_database = $database;
        $this->connection->clean($c_database);

        // Begin transaction for data consistency (only if not structure-only)
        if (empty($options['structure_only'])) {
            $this->connection->beginDump();
        }

        // Database settings
        $this->write("-- Database settings\n");
        if (!empty($options['clean'])) {
            $this->write("DROP DATABASE IF EXISTS \"" . addslashes($c_database) . "\" CASCADE;\n");
        }
        $this->write("CREATE DATABASE " . $this->getIfNotExists($options) . "\"" . addslashes($c_database) . "\";\n");
        $this->write("\\c \"" . addslashes($c_database) . "\"\n\n");

        // Save current database and reconnect to target database
        $originalDatabase = $this->connection->conn->database;
        $this->connection->conn->close();

        // Reconnect to the target database
        $serverInfo = AppContainer::getMisc()->getServerInfo();
        $this->connection->conn->connect(
            $serverInfo['host'],
            $serverInfo['username'] ?? '',
            $serverInfo['password'] ?? '',
            $database,
            $serverInfo['port'] ?? 5432
        );

        // Iterate through schemas
        $schemaActions = new SchemaActions($this->connection);
        $schemas = $schemaActions->getSchemas();

        $dumper = DumpFactory::create('schema', $this->connection);
        while ($schemas && !$schemas->EOF) {
            $schemaName = $schemas->fields['nspname'];

            // Skip system schemas unless requested
            if (empty($options['all_schemas']) && ($schemaName === 'information_schema' || strpos($schemaName, 'pg_') === 0)) {
                $schemas->moveNext();
                continue;
            }

            $dumper->dump('schema', ['schema' => $schemaName], $options);
            $schemas->moveNext();
        }

        $this->writePrivileges($database, 'database');

        // End transaction for this database
        if (empty($options['structure_only'])) {
            $this->connection->endDump();
        }

        // Reconnect to original database
        $this->connection->conn->close();
        $this->connection->conn->connect(
            $serverInfo['host'],
            $serverInfo['username'] ?? '',
            $serverInfo['password'] ?? '',
            $originalDatabase,
            $serverInfo['port'] ?? 5432
        );
    }
}
