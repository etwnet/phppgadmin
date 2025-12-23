<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * Dumper for PostgreSQL tablespaces.
 */
class TablespaceDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $tablespaceActions = new TablespaceActions($this->connection);
        $tablespaces = $tablespaceActions->getTablespaces();

        $this->write("\n-- Tablespaces\n");

        while ($tablespaces && !$tablespaces->EOF) {
            $spcname = $tablespaces->fields['spcname'];

            // Skip default tablespaces
            if ($spcname === 'pg_default' || $spcname === 'pg_global') {
                $tablespaces->moveNext();
                continue;
            }

            $this->writeDrop('TABLESPACE', $spcname, $options);

            $this->write("CREATE TABLESPACE \"{$spcname}\"");
            if (!empty($tablespaces->fields['spclocation'])) {
                $this->write(" LOCATION '{$tablespaces->fields['spclocation']}'");
            }
            $this->write(";\n");

            $this->writePrivileges($spcname, 'tablespace');

            $tablespaces->moveNext();
        }
    }
}
