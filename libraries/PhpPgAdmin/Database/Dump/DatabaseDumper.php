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

        // Emit global preliminaries (ON_ERROR_STOP) unless suppressed
        if (empty($options['suppress_preliminaries'])) {
            $this->write("\\set ON_ERROR_STOP on\n\n");
        }

        // Database settings
        // Optionally suppress creation and/or connect markers when orchestrated by ServerDumper
        if (empty($options['suppress_create_database'])) {
            $this->write("-- Database settings\n");
            if (!empty($options['clean'])) {
                $this->write("DROP DATABASE IF EXISTS \"" . addslashes($c_database) . "\" CASCADE;\n");
            }
            $this->write("CREATE DATABASE " . $this->getIfNotExists($options) . "\"" . addslashes($c_database) . "\";\n");
        }

        if (empty($options['suppress_connect'])) {
            // Use full \connect command for clarity and append encoding + pg_dump-like preliminaries
            $this->write("\\connect \"" . addslashes($c_database) . "\"\n");
            $this->write("\\encoding UTF8\n");
            $this->write("SET client_encoding = 'UTF8';\n");
            // pg_dump session settings for reliable restores
            $this->write("SET statement_timeout = 0;\n");
            $this->write("SET lock_timeout = 0;\n");
            $this->write("SET idle_in_transaction_session_timeout = 0;\n");
            $this->write("SET transaction_timeout = 0;\n");
            $this->write("SET standard_conforming_strings = on;\n");
            $this->write("SELECT pg_catalog.set_config('search_path', '', false);\n");
            $this->write("SET check_function_bodies = false;\n");
            $this->write("SET xmloption = content;\n");
            $this->write("SET client_min_messages = warning;\n");
            $this->write("SET row_security = off;\n");
            // Leave a blank line before objects
            $this->write("\n");
        } else {
            // Keep spacing consistent
            $this->write("\n");
        }

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

        $dumper = $this->createSubDumper('schema');
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
