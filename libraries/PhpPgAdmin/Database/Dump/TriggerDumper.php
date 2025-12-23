<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL triggers.
 */
class TriggerDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $name = $params['trigger'] ?? null;
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$name || !$table) {
            return;
        }

        $this->write("\n-- Trigger: \"{$name}\" ON \"{$schema}\".\"{$table}\"\n");

        if (!empty($options['clean'])) {
            $this->write("DROP TRIGGER IF EXISTS \"{$name}\" ON \"{$schema}\".\"{$table}\" CASCADE;\n");
        }

        // pg_get_triggerdef(oid) is available since 9.0
        $sql = "SELECT pg_get_triggerdef(oid) as definition FROM pg_trigger WHERE tgname = '{$name}' AND tgrelid = (SELECT oid FROM pg_class WHERE relname = '{$table}' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '{$schema}'))";
        $defRs = $this->connection->selectSet($sql);

        if ($defRs && !$defRs->EOF) {
            $this->write($defRs->fields['definition'] . ";\n");
        }
    }
}
