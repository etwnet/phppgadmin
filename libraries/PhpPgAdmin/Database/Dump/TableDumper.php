<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\TableActions;

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

        $format = $options['format'] ?? 'copy';
        $oids = !empty($options['oids']);

        // Set fetch mode to NUM for data dumping
        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);

        $rs = $this->connection->dumpRelation($table, $oids);

        if ($format === 'copy') {
            $this->write("COPY \"{$schema}\".\"{$table}\" FROM stdin;\n");
            while ($rs && !$rs->EOF) {
                $line = [];
                foreach ($rs->fields as $v) {
                    if ($v === null) {
                        $line[] = '\\N';
                    } else {
                        // Basic escaping for COPY format
                        $v = str_replace(["\\", "\t", "\n", "\r"], ["\\\\", "\\t", "\\n", "\\r"], $v);
                        $line[] = $v;
                    }
                }
                $this->write(implode("\t", $line) . "\n");
                $rs->moveNext();
            }
            $this->write("\\.\n");
        } else {
            // INSERT format
            while ($rs && !$rs->EOF) {
                $fields = [];
                $values = [];
                $j = 0;
                foreach ($rs->fields as $v) {
                    $finfo = $rs->fetchField($j++);
                    $fields[] = "\"{$finfo->name}\"";

                    if ($v === null) {
                        $values[] = 'NULL';
                    } else {
                        $this->connection->clean($v);
                        $values[] = "'{$v}'";
                    }
                }
                $this->write("INSERT INTO \"{$schema}\".\"{$table}\" (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ");\n");
                $rs->moveNext();
            }
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
}
