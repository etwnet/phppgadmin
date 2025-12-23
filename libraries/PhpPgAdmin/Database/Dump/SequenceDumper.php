<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\SequenceActions;

/**
 * Dumper for PostgreSQL sequences.
 */
class SequenceDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $sequence = $params['sequence'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$sequence) {
            return;
        }

        $sequenceActions = new SequenceActions($this->connection);
        $rs = $sequenceActions->getSequence($sequence);

        if ($rs && !$rs->EOF) {
            $this->writeDrop('SEQUENCE', "{$schema}\".\"{$sequence}", $options);

            $ifNotExists = $this->getIfNotExists($options);
            $this->write("CREATE SEQUENCE {$ifNotExists}\"{$schema}\".\"{$sequence}\"\n");
            $this->write("    START WITH {$rs->fields['start_value']}\n");
            $this->write("    INCREMENT BY {$rs->fields['increment_by']}\n");
            $this->write("    MINVALUE {$rs->fields['min_value']}\n");
            $this->write("    MAXVALUE {$rs->fields['max_value']}\n");
            $this->write("    CACHE {$rs->fields['cache_value']}");
            if ($this->connection->phpBool($rs->fields['is_cycled'])) {
                $this->write("\n    CYCLE");
            }
            $this->write(";\n");

            // Set the current value
            if (!empty($rs->fields['last_value'])) {
                $this->write("SELECT pg_catalog.setval('\"{$schema}\".\"{$sequence}\"', {$rs->fields['last_value']}, " . ($this->connection->phpBool($rs->fields['is_called']) ? 'true' : 'false') . ");\n");
            }

            $this->writePrivileges($sequence, 'sequence', $schema);
        }
    }
}
