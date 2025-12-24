<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AggregateActions;
use PhpPgAdmin\Database\Actions\OperatorActions;
use PhpPgAdmin\Database\Actions\SequenceActions;
use PhpPgAdmin\Database\Actions\SqlFunctionActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\ViewActions;

/**
 * Orchestrator dumper for a PostgreSQL schema.
 */
class SchemaDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $schema = $params['schema'] ?? $this->connection->_schema;
        if (!$schema) {
            return;
        }

        // Save and set schema context for Actions that depend on it
        $oldSchema = $this->connection->_schema;
        $this->connection->_schema = $schema;

        // Also save and set database context if provided
        $oldDatabase = $this->connection->conn->database;
        if (!empty($params['database']) && $params['database'] !== $oldDatabase) {
            $this->connection->conn->database = $params['database'];
        }

        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $this->writeDrop('SCHEMA', $c_schema, $options);
        $this->write("CREATE SCHEMA " . $this->getIfNotExists($options) . "\"" . addslashes($c_schema) . "\";\n");
        $this->write("SET search_path = \"" . addslashes($c_schema) . "\", pg_catalog;\n\n");

        // 1. Types & Domains
        $this->dumpTypes($schema, $options);

        // 2. Sequences
        $this->dumpSequences($schema, $options);

        // 3. Tables
        $this->dumpTables($schema, $options);

        // 4. Views
        $this->dumpViews($schema, $options);

        // 5. Functions
        $this->dumpFunctions($schema, $options);

        // 6. Aggregates, Operators, etc.
        $this->dumpOtherObjects($schema, $options);

        $this->writePrivileges($schema, 'schema');

        // Restore original contexts
        $this->connection->_schema = $oldSchema;
        $this->connection->conn->database = $oldDatabase;
    }

    protected function dumpTypes($schema, $options)
    {
        $typeActions = new TypeActions($this->connection);
        $types = $typeActions->getTypes(false, false, true); // include domains
        $typeDumper = DumpFactory::create('type', $this->connection);
        $domainDumper = DumpFactory::create('domain', $this->connection);

        while ($types && !$types->EOF) {
            if ($types->fields['typtype'] === 'd') {
                $domainDumper->dump('domain', ['domain' => $types->fields['typname'], 'schema' => $schema], $options);
            } else {
                $typeDumper->dump('type', ['type' => $types->fields['typname'], 'schema' => $schema], $options);
            }
            $types->moveNext();
        }
    }

    protected function dumpSequences($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT sequence_name AS seqname
                FROM information_schema.sequences
                WHERE sequence_schema = '{$c_schema}'
                ORDER BY sequence_name";

        $sequences = $this->connection->selectSet($sql);
        $dumper = DumpFactory::create('sequence', $this->connection);

        while ($sequences && !$sequences->EOF) {
            $dumper->dump('sequence', ['sequence' => $sequences->fields['seqname'], 'schema' => $schema], $options);
            $sequences->moveNext();
        }
    }

    protected function dumpTables($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT c.relname
                FROM pg_catalog.pg_class c
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'r'
                AND n.nspname = '{$c_schema}'
                ORDER BY c.relname";

        $tables = $this->connection->selectSet($sql);
        $dumper = DumpFactory::create('table', $this->connection);

        while ($tables && !$tables->EOF) {
            $dumper->dump('table', ['table' => $tables->fields['relname'], 'schema' => $schema], $options);
            $tables->moveNext();
        }
    }

    protected function dumpViews($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT c.relname
                FROM pg_catalog.pg_class c
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'v'
                AND n.nspname = '{$c_schema}'
                ORDER BY c.relname";

        $views = $this->connection->selectSet($sql);
        $dumper = DumpFactory::create('view', $this->connection);

        while ($views && !$views->EOF) {
            $dumper->dump('view', ['view' => $views->fields['relname'], 'schema' => $schema], $options);
            $views->moveNext();
        }
    }

    protected function dumpFunctions($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT p.oid AS prooid
                FROM pg_catalog.pg_proc p
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = '{$c_schema}'
                AND p.prokind = 'f'
                ORDER BY p.proname";

        $functions = $this->connection->selectSet($sql);
        $dumper = DumpFactory::create('function', $this->connection);

        while ($functions && !$functions->EOF) {
            $dumper->dump('function', ['function_oid' => $functions->fields['prooid'], 'schema' => $schema], $options);
            $functions->moveNext();
        }
    }

    protected function dumpOtherObjects($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        // Aggregates
        $sql = "SELECT p.proname, pg_catalog.pg_get_function_arguments(p.oid) AS proargtypes
                FROM pg_catalog.pg_proc p
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = '{$c_schema}'
                AND p.prokind = 'a'
                ORDER BY p.proname";

        $aggregates = $this->connection->selectSet($sql);
        $aggDumper = DumpFactory::create('aggregate', $this->connection);

        while ($aggregates && !$aggregates->EOF) {
            $aggDumper->dump('aggregate', [
                'aggregate' => $aggregates->fields['proname'],
                'basetype' => $aggregates->fields['proargtypes'],
                'schema' => $schema
            ], $options);
            $aggregates->moveNext();
        }

        // Operators
        $sql = "SELECT o.oid
                FROM pg_catalog.pg_operator o
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = o.oprnamespace
                WHERE n.nspname = '{$c_schema}'
                ORDER BY o.oid";

        $operators = $this->connection->selectSet($sql);
        $opDumper = DumpFactory::create('operator', $this->connection);

        while ($operators && !$operators->EOF) {
            $opDumper->dump('operator', [
                'operator_oid' => $operators->fields['oid'],
                'schema' => $schema
            ], $options);
            $operators->moveNext();
        }
    }
}
