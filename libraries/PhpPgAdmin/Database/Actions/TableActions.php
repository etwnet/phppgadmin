<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet;
use PhpPgAdmin\Database\AbstractActions;
use PhpPgAdmin\Database\AbstractConnection;
use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\RuleActions;

class TableActions extends AbstractActions
{
    // Base constructor inherited from Actions

    /** @var AclActions */
    private $acl;

    /** @var ConstraintActions */
    private $constraint;

    /** @var IndexActions */
    private $index;

    /** @var RuleActions */
    private $rule;

    /**
     * @var array
     */
    private $allowedStorage = ['p', 'e', 'm', 'x'];

    private function getAclAction()
    {
        if ($this->acl === null) {
            $this->acl = new AclActions($this->connection);
        }
        return $this->acl;
    }

    private function getConstraintAction()
    {
        if ($this->constraint === null) {
            $this->constraint = new ConstraintActions($this->connection);
        }
        return $this->constraint;
    }

    private function getIndexAction()
    {
        if ($this->index === null) {
            $this->index = new IndexActions($this->connection);
        }
        return $this->index;
    }

    private function getRuleAction()
    {
        if ($this->rule === null) {
            $this->rule = new RuleActions($this->connection);
        }
        return $this->rule;
    }

    private function hasGrantOption()
    {
        return $this->connection->hasGrantOption();
    }

    private function supportsTablespaces()
    {
        return $this->connection->hasTablespaces();
    }


    private function getTriggersForTable($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT
                t.tgname, pg_catalog.pg_get_triggerdef(t.oid) AS tgdef,
                CASE WHEN t.tgenabled = 'D' THEN FALSE ELSE TRUE END AS tgenabled, p.oid AS prooid,
                p.proname || ' (' || pg_catalog.oidvectortypes(p.proargtypes) || ')' AS proproto,
                ns.nspname AS pronamespace
            FROM pg_catalog.pg_trigger t, pg_catalog.pg_proc p, pg_catalog.pg_namespace ns
            WHERE t.tgrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}'
                AND relnamespace=(SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}'))
                AND ( tgconstraint = 0 OR NOT EXISTS
                        (SELECT 1 FROM pg_catalog.pg_depend d    JOIN pg_catalog.pg_constraint c
                            ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                        WHERE d.classid = t.tableoid AND d.objid = t.oid AND d.deptype = 'i' AND c.contype = 'f'))
                AND p.oid=t.tgfoid
                AND p.pronamespace = ns.oid";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns table information.
     */
    public function getTable($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "
            SELECT
              c.relname, n.nspname, u.usename AS relowner,
              pg_catalog.obj_description(c.oid, 'pg_class') AS relcomment,
              (SELECT spcname FROM pg_catalog.pg_tablespace pt WHERE pt.oid=c.reltablespace) AS tablespace
            FROM pg_catalog.pg_class c
                 LEFT JOIN pg_catalog.pg_user u ON u.usesysid = c.relowner
                 LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r'
                  AND n.nspname = '{$c_schema}'
                  AND n.oid = c.relnamespace
                  AND c.relname = '{$table}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Get type of table/view.
     */
    public function getTableType($schema, $table)
    {
        $this->connection->clean($schema);
        $this->connection->clean($table);
        $sql = "SELECT c.relkind
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = '$schema'
              AND c.relname = '$table'";
        $type = $this->connection->selectField($sql, 'relkind');
        if ($type == 'r' || $type == 'f') {
            return 'table';
        }
        if ($type == 'v' || $type == 'm') {
            return 'view';
        }
        return null;
    }

    /**
     * Return all tables in current database (and schema).
     */
    public function getTables($all = false)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        if ($all) {
            $sql = "SELECT schemaname AS nspname, tablename AS relname, tableowner AS relowner
                    FROM pg_catalog.pg_tables
                    WHERE schemaname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
                    ORDER BY schemaname, tablename";
        } else {
            $sql = "SELECT c.relname, pg_catalog.pg_get_userbyid(c.relowner) AS relowner,
                        pg_catalog.obj_description(c.oid, 'pg_class') AS relcomment,
                        reltuples::bigint,
                        (SELECT spcname FROM pg_catalog.pg_tablespace pt WHERE pt.oid=c.reltablespace) AS tablespace
                    FROM pg_catalog.pg_class c
                    LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relkind = 'r'
                    AND nspname='{$c_schema}'
                    ORDER BY c.relname";
        }

        return $this->connection->selectSet($sql);
    }

    /**
     * Retrieve the attribute definition of a table.
     */
    public function getTableAttributes($table, $field = '')
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);
        $this->connection->clean($field);

        if ($field == '') {
            $sql = "
                SELECT
                    a.attname, a.attnum,
                    pg_catalog.format_type(a.atttypid, a.atttypmod) as type,
                    a.atttypmod,
                    a.attnotnull, a.atthasdef, pg_catalog.pg_get_expr(adef.adbin, adef.adrelid, true) as adsrc,
                    a.attstattarget, a.attstorage, t.typstorage,
                    (
                        SELECT 1 FROM pg_catalog.pg_depend pd, pg_catalog.pg_class pc
                        WHERE pd.objid=pc.oid
                        AND pd.classid=pc.tableoid
                        AND pd.refclassid=pc.tableoid
                        AND pd.refobjid=a.attrelid
                        AND pd.refobjsubid=a.attnum
                        AND pd.deptype='i'
                        AND pc.relkind='S'
                    ) IS NOT NULL AS attisserial,
                    pg_catalog.col_description(a.attrelid, a.attnum) AS comment
                FROM
                    pg_catalog.pg_attribute a LEFT JOIN pg_catalog.pg_attrdef adef
                    ON a.attrelid=adef.adrelid
                    AND a.attnum=adef.adnum
                    LEFT JOIN pg_catalog.pg_type t ON a.atttypid=t.oid
                WHERE
                    a.attrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}'
                        AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE
                        nspname = '{$c_schema}'))
                    AND a.attnum > 0 AND NOT a.attisdropped
                ORDER BY a.attnum";
        } else {
            $sql = "
                SELECT
                    a.attname, a.attnum,
                    pg_catalog.format_type(a.atttypid, a.atttypmod) as type,
                    pg_catalog.format_type(a.atttypid, NULL) as base_type,
                    a.atttypmod,
                    a.attnotnull, a.atthasdef, pg_catalog.pg_get_expr(adef.adbin, adef.adrelid, true) as adsrc,
                    a.attstattarget, a.attstorage, t.typstorage,
                    pg_catalog.col_description(a.attrelid, a.attnum) AS comment
                FROM
                    pg_catalog.pg_attribute a LEFT JOIN pg_catalog.pg_attrdef adef
                    ON a.attrelid=adef.adrelid
                    AND a.attnum=adef.adnum
                    LEFT JOIN pg_catalog.pg_type t ON a.atttypid=t.oid
                WHERE
                    a.attrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}'
                        AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE
                        nspname = '{$c_schema}'))
                    AND a.attname = '{$field}'";
        }

        return $this->connection->selectSet($sql);
    }

    /**
     * Finds parent tables.
     */
    public function getTableParents($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "
            SELECT
                pn.nspname, relname
            FROM
                pg_catalog.pg_class pc, pg_catalog.pg_inherits pi, pg_catalog.pg_namespace pn
            WHERE
                pc.oid=pi.inhparent
                AND pc.relnamespace=pn.oid
                AND pi.inhrelid = (SELECT oid from pg_catalog.pg_class WHERE relname='{$table}'
                    AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = '{$c_schema}'))
            ORDER BY
                pi.inhseqno
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Finds child tables.
     */
    public function getTableChildren($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "
            SELECT
                pn.nspname, relname
            FROM
                pg_catalog.pg_class pc, pg_catalog.pg_inherits pi, pg_catalog.pg_namespace pn
            WHERE
                pc.oid=pi.inhrelid
                AND pc.relnamespace=pn.oid
                AND pi.inhparent = (SELECT oid from pg_catalog.pg_class WHERE relname='{$table}'
                    AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = '{$c_schema}'))
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Get table definition prefix. Must be run within a transaction.
     */
    public function getTableDefPrefix($table, $clean = false)
    {
        $t = $this->getTable($table);
        if (!is_object($t) || $t->recordCount() != 1) {
            $this->connection->rollbackTransaction();
            return null;
        }
        $this->connection->fieldClean($t->fields['relname']);
        $this->connection->fieldClean($t->fields['nspname']);

        $atts = $this->getTableAttributes($table);
        if (!is_object($atts)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        $cons = $this->getConstraintAction()->getConstraints($table);
        if (!is_object($cons)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        $sql = $this->getChangeUserSQL($t->fields['relowner']) . "\n\n";
        $sql .= "SET search_path = \"{$t->fields['nspname']}\", pg_catalog;\n\n";
        $sql .= "-- Definition\n\n";
        if (!$clean) $sql .= "-- ";
        $sql .= "DROP TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\";\n";
        $sql .= "CREATE TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" (\n";

        $col_comments_sql = '';
        $num = $atts->recordCount() + $cons->recordCount();
        $i = 1;
        while (!$atts->EOF) {
            $this->connection->fieldClean($atts->fields['attname']);
            $sql .= "    \"{$atts->fields['attname']}\"";
            if ($this->connection->phpBool($atts->fields['attisserial']) &&
                ($atts->fields['type'] == 'integer' || $atts->fields['type'] == 'bigint')) {
                $sql .= ($atts->fields['type'] == 'integer') ? " SERIAL" : " BIGSERIAL";
            } else {
                $sql .= " " . $this->connection->formatType($atts->fields['type'], $atts->fields['atttypmod']);
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $sql .= " NOT NULL";
                }
                if ($atts->fields['adsrc'] !== null) {
                    $sql .= " DEFAULT {$atts->fields['adsrc']}";
                }
            }

            if ($i < $num) {
                $sql .= ",\n";
            } else {
                $sql .= "\n";
            }

            if ($atts->fields['comment'] !== null) {
                $this->connection->clean($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN \"{$t->fields['relname']}\".\"{$atts->fields['attname']}\"  IS '{$atts->fields['comment']}';\n";
            }

            $atts->moveNext();
            $i++;
        }

        while (!$cons->EOF) {
            $this->connection->fieldClean($cons->fields['conname']);
            $sql .= "    CONSTRAINT \"{$cons->fields['conname']}\" ";
            if ($cons->fields['consrc'] !== null) {
                $sql .= $cons->fields['consrc'];
            } else {
                switch ($cons->fields['contype']) {
                case 'p':
                    $keys = $this->getAttributeNames($table, explode(' ', $cons->fields['indkey']));
                    $sql .= "PRIMARY KEY (" . join(',', $keys) . ")";
                    break;
                case 'u':
                    $keys = $this->getAttributeNames($table, explode(' ', $cons->fields['indkey']));
                    $sql .= "UNIQUE (" . join(',', $keys) . ")";
                    break;
                default:
                    $this->connection->rollbackTransaction();
                    return null;
                }
            }

            if ($i < $num) {
                $sql .= ",\n";
            } else {
                $sql .= "\n";
            }

            $cons->moveNext();
            $i++;
        }

        $sql .= ")";

        if ($this->hasObjectID($table)) {
            $sql .= " WITH OIDS";
        } else {
            $sql .= " WITHOUT OIDS";
        }

        $sql .= ";\n";

        $atts->moveFirst();
        $first = true;
        while (!$atts->EOF) {
            $this->connection->fieldClean($atts->fields['attname']);
            if ($atts->fields['attstattarget'] >= 0) {
                if ($first) {
                    $sql .= "\n";
                    $first = false;
                }
                $sql .= "ALTER TABLE ONLY \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" ALTER COLUMN \"{$atts->fields['attname']}\" SET STATISTICS {$atts->fields['attstattarget']};\n";
            }
            if ($atts->fields['attstorage'] != $atts->fields['typstorage']) {
                $storage = null;
                switch ($atts->fields['attstorage']) {
                case 'p':
                    $storage = 'PLAIN';
                    break;
                case 'e':
                    $storage = 'EXTERNAL';
                    break;
                case 'm':
                    $storage = 'MAIN';
                    break;
                case 'x':
                    $storage = 'EXTENDED';
                    break;
                default:
                    $this->connection->rollbackTransaction();
                    return null;
                }
                $sql .= "ALTER TABLE ONLY \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" ALTER COLUMN \"{$atts->fields['attname']}\" SET STORAGE {$storage};\n";
            }

            $atts->moveNext();
        }

        if ($t->fields['relcomment'] !== null) {
            $this->connection->clean($t->fields['relcomment']);
            $sql .= "\n-- Comment\n\n";
            $sql .= "COMMENT ON TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" IS '{$t->fields['relcomment']}';\n";
        }

        if ($col_comments_sql != '') {
            $sql .= $col_comments_sql;
        }

        $privs = $this->getAclAction()->getPrivileges($table, 'table');
        if (!is_array($privs)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if (sizeof($privs) > 0) {
            $sql .= "\n-- Privileges\n\n";
            $sql .= "REVOKE ALL ON TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" FROM PUBLIC;\n";
            foreach ($privs as $v) {
                $nongrant = array_diff($v[2], $v[4]);
                if (sizeof($v[2]) == 0 || ($v[0] == 'user' && $v[1] == $t->fields['relowner'])) continue;
                if ($this->hasGrantOption() && $v[3] != $t->fields['relowner']) {
                    $grantor = $v[3];
                    $this->connection->clean($grantor);
                    $sql .= "SET SESSION AUTHORIZATION '{$grantor}';\n";
                }
                $sql .= "GRANT " . join(', ', $nongrant) . " ON TABLE \"{$t->fields['relname']}\" TO ";
                switch ($v[0]) {
                case 'public':
                    $sql .= "PUBLIC;\n";
                    break;
                case 'user':
                    $this->connection->fieldClean($v[1]);
                    $sql .= "\"{$v[1]}\";\n";
                    break;
                case 'group':
                    $this->connection->fieldClean($v[1]);
                    $sql .= "GROUP \"{$v[1]}\";\n";
                    break;
                default:
                    $this->connection->rollbackTransaction();
                    return null;
                }

                if ($this->hasGrantOption() && $v[3] != $t->fields['relowner']) {
                    $sql .= "RESET SESSION AUTHORIZATION;\n";
                }

                if (!$this->hasGrantOption() || sizeof($v[4]) == 0) continue;

                if ($this->hasGrantOption() && $v[3] != $t->fields['relowner']) {
                    $grantor = $v[3];
                    $this->connection->clean($grantor);
                    $sql .= "SET SESSION AUTHORIZATION '{$grantor}';\n";
                }

                $sql .= "GRANT " . join(', ', $v[4]) . " ON \"{$t->fields['relname']}\" TO ";
                switch ($v[0]) {
                case 'public':
                    $sql .= "PUBLIC";
                    break;
                case 'user':
                    $this->connection->fieldClean($v[1]);
                    $sql .= "\"{$v[1]}\"";
                    break;
                case 'group':
                    $this->connection->fieldClean($v[1]);
                    $sql .= "GROUP \"{$v[1]}\"";
                    break;
                default:
                    return null;
                }
                $sql .= " WITH GRANT OPTION;\n";

                if ($this->hasGrantOption() && $v[3] != $t->fields['relowner']) {
                    $sql .= "RESET SESSION AUTHORIZATION;\n";
                }
            }
        }

        $sql .= "\n";

        return $sql;
    }

    /**
     * Returns extra table definition (indexes, triggers, rules).
     */
    public function getTableDefSuffix($table)
    {
        $sql = '';

        $indexes = $this->getIndexAction()->getIndexes($table);
        if (!is_object($indexes)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if ($indexes->recordCount() > 0) {
            $sql .= "\n-- Indexes\n\n";
            while (!$indexes->EOF) {
                $sql .= $indexes->fields['inddef'] . ";\n";
                $indexes->moveNext();
            }
        }

        $triggers = $this->getTriggersForTable($table);
        if (!is_object($triggers)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if ($triggers->recordCount() > 0) {
            $sql .= "\n-- Triggers\n\n";
            while (!$triggers->EOF) {
                $sql .= $triggers->fields['tgdef'];
                $sql .= ";\n";
                $triggers->moveNext();
            }
        }

        $rules = $this->getRuleAction()->getRules($table);
        if (!is_object($rules)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if ($rules->recordCount() > 0) {
            $sql .= "\n-- Rules\n\n";
            while (!$rules->EOF) {
                $sql .= $rules->fields['definition'] . "\n";
                $rules->moveNext();
            }
        }

        return $sql;
    }

    /**
     * Creates a new table.
     */
    public function createTable($name, $fields, $field, $type, $array, $length, $notnull,
        $default, $withoutoids, $colcomment, $tblcomment, $tablespace,
        $uniquekey, $primarykey)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);

        $status = $this->connection->beginTransaction();
        if ($status != 0) return -1;

        $found = false;
        $first = true;
        $comment_sql = '';
        $sql = "CREATE TABLE \"{$f_schema}\".\"{$name}\" (";
        for ($i = 0; $i < $fields; $i++) {
            $this->connection->fieldClean($field[$i]);
            $this->connection->clean($type[$i]);
            $this->connection->clean($length[$i]);
            $this->connection->clean($colcomment[$i]);

            if ($field[$i] == '' || $type[$i] == '') continue;
            if (!$first) $sql .= ", ";
            else $first = false;

            switch ($type[$i]) {
            case 'timestamp with time zone':
            case 'timestamp without time zone':
                $qual = substr($type[$i], 9);
                $sql .= "\"{$field[$i]}\" timestamp";
                if ($length[$i] != '') $sql .= "({$length[$i]})";
                $sql .= $qual;
                break;
            case 'time with time zone':
            case 'time without time zone':
                $qual = substr($type[$i], 4);
                $sql .= "\"{$field[$i]}\" time";
                if ($length[$i] != '') $sql .= "({$length[$i]})";
                $sql .= $qual;
                break;
            default:
                $sql .= "\"{$field[$i]}\" {$type[$i]}";
                if ($length[$i] != '') $sql .= "({$length[$i]})";
            }
            if ($array[$i] == '[]') $sql .= '[]';
            if (!isset($primarykey[$i])) {
                if (isset($uniquekey[$i])) $sql .= " UNIQUE";
                if (isset($notnull[$i])) $sql .= " NOT NULL";
            }
            if ($default[$i] != '') $sql .= " DEFAULT {$default[$i]}";

            if ($colcomment[$i] != '') $comment_sql .= "COMMENT ON COLUMN \"{$name}\".\"{$field[$i]}\" IS '{$colcomment[$i]}';\n";

            $found = true;
        }

        if (!$found) return -1;

        $primarykeycolumns = array();
        for ($i = 0; $i < $fields; $i++) {
            if (isset($primarykey[$i])) {
                $primarykeycolumns[] = "\"{$field[$i]}\"";
            }
        }
        if (count($primarykeycolumns) > 0) {
            $sql .= ", PRIMARY KEY (" . implode(", ", $primarykeycolumns) . ")";
        }

        $sql .= ")";

        if ($withoutoids)
            $sql .= ' WITHOUT OIDS';
        else
            $sql .= ' WITH OIDS';

        if ($this->supportsTablespaces() && $tablespace != '') {
            $this->connection->fieldClean($tablespace);
            $sql .= " TABLESPACE \"{$tablespace}\"";
        }

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($tblcomment != '') {
            $status = $this->connection->setComment('TABLE', '', $name, $tblcomment, true);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        if ($comment_sql != '') {
            $status = $this->connection->execute($comment_sql);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }
        return $this->connection->endTransaction();
    }

    /**
     * Creates a table LIKE another table.
     */
    public function createTableLike($name, $like, $defaults = false, $constraints = false, $idx = false, $tablespace = '')
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($like['schema']);
        $this->connection->fieldClean($like['table']);
        $likeStr = "\"{$like['schema']}\".\"{$like['table']}\"";

        $status = $this->connection->beginTransaction();
        if ($status != 0) return -1;

        $sql = "CREATE TABLE \"{$f_schema}\".\"{$name}\" (LIKE {$likeStr}";

        if ($defaults) $sql .= " INCLUDING DEFAULTS";
        if ($constraints) $sql .= " INCLUDING CONSTRAINTS";
        if ($idx) $sql .= " INCLUDING INDEXES";

        $sql .= ")";

        if ($this->supportsTablespaces() && $tablespace != '') {
            $this->connection->fieldClean($tablespace);
            $sql .= " TABLESPACE \"{$tablespace}\"";
        }

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Alter a table's name.
     */
    public function alterTableName($tblrs, $name = null)
    {
        if (!empty($name) && ($name != $tblrs->fields['relname'])) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status == 0) {
                $tblrs->fields['relname'] = $name;
            } else {
                return $status;
            }
        }
        return 0;
    }

    /**
     * Alter a table's owner.
     */
    public function alterTableOwner($tblrs, $owner = null)
    {
        if (!empty($owner) && ($tblrs->fields['relowner'] != $owner)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" OWNER TO \"{$owner}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Alter a table's tablespace.
     */
    public function alterTableTablespace($tblrs, $tablespace = null)
    {
        if (!empty($tablespace) && ($tblrs->fields['tablespace'] != $tablespace)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" SET TABLESPACE \"{$tablespace}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Alter a table's schema.
     */
    public function alterTableSchema($tblrs, $schema = null)
    {
        if (!empty($schema) && ($tblrs->fields['nspname'] != $schema)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" SET SCHEMA \"{$schema}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Internal alter table helper (transactional context expected).
     */
    private function alterTableInternal($tblrs, $name, $owner, $schema, $comment, $tablespace)
    {
        $this->connection->fieldArrayClean($tblrs->fields);

        $status = $this->connection->setComment('TABLE', '', $tblrs->fields['relname'], $comment);
        if ($status != 0) return -4;

        $this->connection->fieldClean($owner);
        $status = $this->alterTableOwner($tblrs, $owner);
        if ($status != 0) return -5;

        $this->connection->fieldClean($tablespace);
        $status = $this->alterTableTablespace($tblrs, $tablespace);
        if ($status != 0) return -6;

        $this->connection->fieldClean($name);
        $status = $this->alterTableName($tblrs, $name);
        if ($status != 0) return -3;

        $this->connection->fieldClean($schema);
        $status = $this->alterTableSchema($tblrs, $schema);
        if ($status != 0) return -7;

        return 0;
    }

    /**
     * Alter table properties.
     */
    public function alterTable($table, $name, $owner, $schema, $comment, $tablespace)
    {
        $data = $this->getTable($table);

        if ($data->recordCount() != 1)
            return -2;

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $status = $this->alterTableInternal($data, $name, $owner, $schema, $comment, $tablespace);

        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return $status;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Returns SQL for changing current user.
     */
    private function getChangeUserSQL($user)
    {
        $this->connection->clean($user);
        return "SET SESSION AUTHORIZATION '{$user}';";
    }

    /**
     * Map attnum list to attname list for a relation.
     */
    public function getAttributeNames($table, $atts)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);
        $this->connection->arrayClean($atts);

        if (!is_array($atts)) return -1;
        if (sizeof($atts) == 0) return array();

        $sql = "SELECT attnum, attname FROM pg_catalog.pg_attribute WHERE
            attrelid=(SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}' AND
            relnamespace=(SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}'))
            AND attnum IN ('" . join("','", $atts) . "')";

        $rs = $this->connection->selectSet($sql);
        if ($rs->recordCount() != sizeof($atts)) {
            return -2;
        } else {
            $temp = array();
            while (!$rs->EOF) {
                $temp[$rs->fields['attnum']] = $rs->fields['attname'];
                $rs->moveNext();
            }
            return $temp;
        }
    }

    /**
     * Truncate a table (delete all rows).
     */
    public function emptyTable($table)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);

        $sql = "DELETE FROM \"{$f_schema}\".\"{$table}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Drop a table.
     */
    public function dropTable($table, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);

        $sql = "DROP TABLE \"{$f_schema}\".\"{$table}\"";
        if ($cascade) $sql .= " CASCADE";

        return $this->connection->execute($sql);
    }

    /**
     * Add a new column to a table.
     */
    public function addColumn($table, $column, $type, $array, $length, $notnull, $default, $comment)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($type);
        $this->connection->clean($length);

		$sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ADD COLUMN \"{$column}\"";
        if ($length == '')
            $sql .= " {$type}";
        else {
            switch ($type) {
            case 'timestamp with time zone':
            case 'timestamp without time zone':
                $qual = substr($type, 9);
                $sql .= " timestamp({$length}){$qual}";
                break;
            case 'time with time zone':
            case 'time without time zone':
                $qual = substr($type, 4);
                $sql .= " time({$length}){$qual}";
                break;
            default:
                $sql .= " {$type}({$length})";
            }
        }

        if ($array) $sql .= '[]';
		if ($notnull) $sql .= ' NOT NULL';
		if ($default != '') $sql .= ' DEFAULT ' . $default;

        $status = $this->connection->execute($sql);
        if ($status == 0 && trim($comment) != '') {
            $this->connection->setComment('COLUMN', $column, $table, $comment, true);
        }

        return $status;
    }

    /**
     * Rename a column.
     */
    public function renameColumn($table, $column, $newName)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->fieldClean($newName);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" RENAME COLUMN \"{$column}\" TO \"{$newName}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Set a column's DEFAULT.
     */
    public function setColumnDefault($table, $column, $default)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($default);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET DEFAULT {$default}";

        return $this->connection->execute($sql);
    }

    /**
     * Drop a column's DEFAULT.
     */
    public function dropColumnDefault($table, $column)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" DROP DEFAULT";

        return $this->connection->execute($sql);
    }

    /**
     * Set column nullability.
     */
    public function setColumnNull($table, $column, $state)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\"";
        $sql .= ($state) ? ' SET' : ' DROP';
        $sql .= ' NOT NULL';

        return $this->connection->execute($sql);
    }

    /**
     * Drop a column.
     */
    public function dropColumn($table, $column, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" DROP COLUMN \"{$column}\"";
        if ($cascade) $sql .= " CASCADE";

        return $this->connection->execute($sql);
    }

    /**
     * Set column statistics target.
     */
    public function setColumnStats($table, $column, $value)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($value);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET STATISTICS {$value}";

        return $this->connection->execute($sql);
    }

    /**
     * Set column storage.
     */
    public function setColumnStorage($table, $column, $storage)
    {
        if (!in_array($storage, $this->allowedStorage)) {
            return -1;
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($storage);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET STORAGE {$storage}";

        return $this->connection->execute($sql);
    }

    /**
     * Set column compression (Pg14+). Included for completeness.
     */
    public function setColumnCompression($table, $column, $compression)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($compression);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET COMPRESSION {$compression}";

        return $this->connection->execute($sql);
    }

    /**
     * Set column type.
     */
    public function setColumnType($table, $column, $type, $length, $array, $default, $notnull)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($type);
        $this->connection->clean($length);

        if ($length == '')
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE {$type}";
        else {
            switch ($type) {
            case 'timestamp with time zone':
            case 'timestamp without time zone':
                $qual = substr($type, 9);
                $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE timestamp({$length}){$qual}";
                break;
            case 'time with time zone':
            case 'time without time zone':
                $qual = substr($type, 4);
                $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE time({$length}){$qual}";
                break;
            default:
                $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE {$type}({$length})";
            }
        }

        if ($array) $sql .= '[]';
        if ($default != '') $sql .= " USING {$default}";
        $status = $this->connection->execute($sql);

        if ($status == 0) {
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\"";
            if ($notnull) {
                $sql .= ' SET NOT NULL';
            } else {
                $sql .= ' DROP NOT NULL';
            }
            $status = $this->connection->execute($sql);
        }

        return $status;
    }
}
