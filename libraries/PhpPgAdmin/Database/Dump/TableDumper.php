<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Export\SqlFormatter;

/**
 * Dumper for PostgreSQL tables (structure and data).
 */
class TableDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        $this->write("\n-- Table: \"{$schema}\".\"{$table}\"\n");

        if (empty($options['data_only'])) {
            $this->dumpStructure($table, $schema, $options);
        }

        if (empty($options['structure_only'])) {
            $this->dumpData($table, $schema, $options);
        }

        if (empty($options['data_only'])) {
            $this->dumpConstraintsAndIndexes($table, $schema, $options);
        }
    }

    protected function dumpStructure($table, $schema, $options)
    {
        $tableActions = new TableActions($this->connection);

        // Use existing logic from TableActions/Postgres driver but adapted
        $prefix = $tableActions->getTableDefPrefix($table, !empty($options['clean']));
        if ($prefix) {
            // Handle IF NOT EXISTS if requested
            if (!empty($options['if_not_exists'])) {
                $prefix = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $prefix);
            }
            $this->write($prefix);
        }
    }

    protected function dumpData($table, $schema, $options)
    {
        $this->write("\n-- Data for table \"{$schema}\".\"{$table}\"\n");

        $insertFormat = $options['insert_format'] ?? 'copy'; // 'copy', 'single', or 'multi'
        $oids = !empty($options['oids']);

        // Set fetch mode to NUM for data dumping
        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);

        $rs = $this->connection->dumpRelation($table, $oids);

        if (!$rs) {
            // No recordset at all
            return;
        }

        // Move to first record (recordset may be positioned at EOF after initial select)
        if (is_callable([$rs, 'moveFirst'])) {
            $rs->moveFirst();
        }

        // Check if there's actually data after moving to first record
        if ($rs->EOF) {
            // No data to export
            return;
        }

        // Use SqlFormatter to generate SQL output
        $formatter = new SqlFormatter();

        // Set formatter to use dumper's output stream
        $formatter->setOutputStream($this->outputStream);

        // Format the recordset and write to output
        $metadata = [
            'table' => "\"{$schema}\".\"{$table}\"",
            'insert_format' => $insertFormat
        ];

        $sql = $formatter->format($rs, $metadata);

        // If formatter didn't write to stream (no outputStream set), write the accumulated string
        if ($sql) {
            $this->write($sql);
        }

        // Restore fetch mode
        $this->connection->conn->setFetchMode(ADODB_FETCH_ASSOC);
    }

    protected function dumpConstraintsAndIndexes($table, $schema, $options)
    {
        $tableActions = new TableActions($this->connection);
        $suffix = $tableActions->getTableDefSuffix($table);
        if ($suffix) {
            $this->write($suffix);
        }

        $this->writePrivileges($table, 'table', $schema);
    }

    /**
     * Get table data as an ADORecordSet for export formatting.
     *
     * @param array $params Table parameters (schema, table)
     * @return mixed ADORecordSet or null if table cannot be read
     */
    public function getTableData($params)
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return null;
        }

        // Use existing dumpRelation method from connection to get table data
        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);
        $recordset = $this->connection->dumpRelation($table, false);

        return $recordset;
    }
}
