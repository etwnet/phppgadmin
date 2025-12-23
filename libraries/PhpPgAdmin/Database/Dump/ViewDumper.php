<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL views.
 */
class ViewDumper extends AbstractDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $view = $params['view'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$view) {
            return;
        }

        $this->connection->clean($view);
        $this->connection->clean($schema);

        $sql = "SELECT c.oid, pg_catalog.pg_get_viewdef(c.oid, true) AS vwdefinition
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relname = '{$view}' AND n.nspname = '{$schema}'";

        $rs = $this->connection->selectSet($sql);

        if ($rs && !$rs->EOF) {
            $oid = $rs->fields['oid'];
            $def = $rs->fields['vwdefinition'];

            $this->writeDrop('VIEW', "{$schema}\".\"{$view}", $options);

            $ifNotExists = $this->getIfNotExists($options);
            // pg_get_viewdef returns just the SELECT part, we need to wrap it
            $this->write("CREATE OR REPLACE VIEW \"{$schema}\".\"{$view}\" AS\n{$def};\n");

            $this->dumpRules($view, $schema, $options);
            $this->dumpTriggers($view, $schema, $options);

            $this->writePrivileges($view, 'view', $schema);
        }
    }

    protected function dumpRules($view, $schema, $options)
    {
        $sql = "SELECT definition FROM pg_rules WHERE schemaname = '{$schema}' AND tablename = '{$view}'";
        $rs = $this->connection->selectSet($sql);
        if ($rs && !$rs->EOF) {
            $this->write("\n-- Rules on view \"{$schema}\".\"{$view}\"\n");
            while (!$rs->EOF) {
                $this->write($rs->fields['definition'] . "\n");
                $rs->moveNext();
            }
        }
    }

    protected function dumpTriggers($view, $schema, $options)
    {
        // pg_get_triggerdef(oid) is available since 9.0
        $sql = "SELECT pg_get_triggerdef(oid) as definition FROM pg_trigger WHERE tgrelid = (SELECT oid FROM pg_class WHERE relname = '{$view}' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '{$schema}'))";
        $rs = $this->connection->selectSet($sql);
        if ($rs && !$rs->EOF) {
            $this->write("\n-- Triggers on view \"{$schema}\".\"{$view}\"\n");
            while (!$rs->EOF) {
                $this->write($rs->fields['definition'] . ";\n");
                $rs->moveNext();
            }
        }
    }
}
