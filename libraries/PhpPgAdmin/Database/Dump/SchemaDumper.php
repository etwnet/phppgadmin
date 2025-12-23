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

        $this->writeHeader("Schema: {$schema}");

        $this->writeDrop('SCHEMA', $schema, $options);
        $this->write("CREATE SCHEMA " . $this->getIfNotExists($options) . "\"{$schema}\";\n");
        $this->write("SET search_path = \"{$schema}\", pg_catalog;\n\n");

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
        $sequenceActions = new SequenceActions($this->connection);
        $sequences = $sequenceActions->getSequences();
        $dumper = DumpFactory::create('sequence', $this->connection);
        while ($sequences && !$sequences->EOF) {
            $dumper->dump('sequence', ['sequence' => $sequences->fields['seqname'], 'schema' => $schema], $options);
            $sequences->moveNext();
        }
    }

    protected function dumpTables($schema, $options)
    {
        $tableActions = new TableActions($this->connection);
        $tables = $tableActions->getTables();
        $dumper = DumpFactory::create('table', $this->connection);
        while ($tables && !$tables->EOF) {
            $dumper->dump('table', ['table' => $tables->fields['relname'], 'schema' => $schema], $options);
            $tables->moveNext();
        }
    }

    protected function dumpViews($schema, $options)
    {
        $viewActions = new ViewActions($this->connection);
        $views = $viewActions->getViews();
        $dumper = DumpFactory::create('view', $this->connection);
        while ($views && !$views->EOF) {
            $dumper->dump('view', ['view' => $views->fields['relname'], 'schema' => $schema], $options);
            $views->moveNext();
        }
    }

    protected function dumpFunctions($schema, $options)
    {
        $functionActions = new SqlFunctionActions($this->connection);
        $functions = $functionActions->getFunctions();
        $dumper = DumpFactory::create('function', $this->connection);
        while ($functions && !$functions->EOF) {
            $dumper->dump('function', ['function_oid' => $functions->fields['prooid'], 'schema' => $schema], $options);
            $functions->moveNext();
        }
    }

    protected function dumpOtherObjects($schema, $options)
    {
        // Aggregates
        $aggregateActions = new AggregateActions($this->connection);
        $aggregates = $aggregateActions->getAggregates();
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
        $operatorActions = new OperatorActions($this->connection);
        $operators = $operatorActions->getOperators();
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
