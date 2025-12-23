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

        $sql = "SELECT pg_catalog.pg_get_functiondef('{$functionOid}'::oid) AS funcdef";
        $rs = $this->connection->selectSet($sql);

        if ($rs && !$rs->EOF) {
            $def = $rs->fields['funcdef'];

            // Handle DROP if requested
            if (!empty($options['clean'])) {
                // We need the function identity to drop it correctly
                $idSql = "SELECT pg_catalog.pg_get_function_identity_arguments('{$functionOid}'::oid) AS funcid, proname, nspname 
                          FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE p.oid = '{$functionOid}'::oid";
                $idRs = $this->connection->selectSet($idSql);
                if ($idRs && !$idRs->EOF) {
                    $this->write("DROP FUNCTION IF EXISTS \"{$idRs->fields['nspname']}\".\"{$idRs->fields['proname']}\"({$idRs->fields['funcid']}) CASCADE;\n");
                }
            }

            $this->write($def . ";\n");

            // Add privileges
            $this->writePrivileges($functionOid, 'function');
        }
    }
}
