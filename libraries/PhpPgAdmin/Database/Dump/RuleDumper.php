<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL rules.
 */
class RuleDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $name = $params['rule'] ?? null;
        $table = $params['table'] ?? $params['view'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$name || !$table) {
            return;
        }

        $this->write("\n-- Rule: \"{$name}\" ON \"{$schema}\".\"{$table}\"\n");

        if (!empty($options['clean'])) {
            $this->write("DROP RULE IF EXISTS \"{$name}\" ON \"{$schema}\".\"{$table}\" CASCADE;\n");
        }

        // pg_get_ruledef(oid) is the easiest way
        $sql = "SELECT pg_get_ruledef(oid) as definition FROM pg_rewrite WHERE rulename = '{$name}' AND ev_class = (SELECT oid FROM pg_class WHERE relname = '{$table}' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '{$schema}'))";
        $defRs = $this->connection->selectSet($sql);

        if ($defRs && !$defRs->EOF) {
            $this->write($defRs->fields['definition'] . ";\n");
        }
    }
}
