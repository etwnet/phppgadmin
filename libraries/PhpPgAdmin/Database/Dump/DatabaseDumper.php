<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;

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

        $this->writeHeader("Database: {$database}");

        // Database settings
        $this->write("-- Database settings\n");
        if (!empty($options['clean'])) {
            $this->write("DROP DATABASE IF EXISTS \"" . addslashes($c_database) . "\" CASCADE;\n");
        }
        $this->write("CREATE DATABASE " . $this->getIfNotExists($options) . "\"" . addslashes($c_database) . "\";\n");
        $this->write("\\c \"" . addslashes($c_database) . "\"\n\n");

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
    }
}
