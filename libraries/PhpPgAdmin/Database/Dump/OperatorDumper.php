<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\OperatorActions;

/**
 * Dumper for PostgreSQL operators.
 */
class OperatorDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $oid = $params['operator_oid'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$oid) {
            return;
        }

        $operatorActions = new OperatorActions($this->connection);
        $rs = $operatorActions->getOperator($oid);

        if ($rs && !$rs->EOF) {
            $name = $rs->fields['oprname'];
            $leftType = $rs->fields['oprleftname'];
            $rightType = $rs->fields['oprrightname'];

            $this->write("\n-- Operator: \"{$schema}\".\"{$name}\"\n");

            if (!empty($options['clean'])) {
                $this->write("DROP OPERATOR IF EXISTS \"{$schema}\".\"{$name}\" (" . ($leftType ?: 'NONE') . ", " . ($rightType ?: 'NONE') . ") CASCADE;\n");
            }

            $this->write("CREATE OPERATOR \"{$schema}\".\"{$name}\" (\n");
            $this->write("    PROCEDURE = {$rs->fields['oprcode']}");

            if ($rs->fields['oprleft'] !== '0') {
                $this->write(",\n    LEFTARG = {$rs->fields['oprleftname']}");
            }
            if ($rs->fields['oprright'] !== '0') {
                $this->write(",\n    RIGHTARG = {$rs->fields['oprrightname']}");
            }
            if ($rs->fields['oprcom'] !== '0') {
                $this->write(",\n    COMMUTATOR = {$rs->fields['oprcomname']}");
            }
            if ($rs->fields['oprnegate'] !== '0') {
                $this->write(",\n    NEGATOR = {$rs->fields['oprnegatename']}");
            }
            if ($rs->fields['oprrest'] !== '-' && $rs->fields['oprrest'] !== '0') {
                $this->write(",\n    RESTRICT = {$rs->fields['oprrest']}");
            }
            if ($rs->fields['oprjoin'] !== '-' && $rs->fields['oprjoin'] !== '0') {
                $this->write(",\n    JOIN = {$rs->fields['oprjoin']}");
            }
            if ($rs->fields['oprhashes'] === 't') {
                $this->write(",\n    HASHES");
            }
            if ($rs->fields['oprmerges'] === 't') {
                $this->write(",\n    MERGES");
            }

            $this->write("\n);\n");

            if ($rs->fields['oprcomment'] !== null) {
                $this->connection->clean($rs->fields['oprcomment']);
                $this->write("COMMENT ON OPERATOR \"{$schema}\".\"{$name}\" (" . ($leftType ?: 'NONE') . ", " . ($rightType ?: 'NONE') . ") IS '{$rs->fields['oprcomment']}';\n");
            }
        }
    }
}
