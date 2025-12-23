<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL functions.
 */
class FunctionDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $functionOid = $params['function_oid'] ?? null;
        if (!$functionOid) {
            return;
        }

        $c_oid = $functionOid;
        $this->connection->clean($c_oid);

        $sql = "SELECT pg_catalog.pg_get_functiondef('{$c_oid}'::oid) AS funcdef";
        $rs = $this->connection->selectSet($sql);

        if (!$rs) {
            return;
        }

        if ($rs->EOF) {
            return;
        }

        $def = $rs->fields['funcdef'];

        // Handle DROP if requested
        if (!empty($options['clean'])) {
            // We need the function identity to drop it correctly
            $idSql = "SELECT pg_catalog.pg_get_function_identity_arguments('{$c_oid}'::oid) AS funcid, proname, nspname 
                      FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE p.oid = '{$c_oid}'::oid";
            $idRs = $this->connection->selectSet($idSql);
            if ($idRs && !$idRs->EOF) {
                $proname = $idRs->fields['proname'];
                $nspname = $idRs->fields['nspname'];
                $this->connection->clean($proname);
                $this->connection->clean($nspname);
                $this->write("DROP FUNCTION IF EXISTS \"" . addslashes($nspname) . "\".\"" . addslashes($proname) . "\"({$idRs->fields['funcid']}) CASCADE;\n");
            }
        }

        $this->write($def . ";\n");
    }
}
