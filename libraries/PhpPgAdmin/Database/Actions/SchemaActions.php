<?php

namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Database\AbstractActions;

class SchemaActions extends AbstractActions
{
    /**
     * Return all schemas in the current database.
     */
    public function getSchemas()
    {
        $conf = $this->conf();

        if (!$conf['show_system']) {
            $where = "WHERE nspname NOT LIKE 'pg@_%' ESCAPE '@' AND nspname != 'information_schema'";
        } else {
            $where = "WHERE nspname !~ '^pg_t(emp_[0-9]+|oast)$'";
        }

        $sql = "
            SELECT pn.nspname, pu.rolname AS nspowner,
                pg_catalog.obj_description(pn.oid, 'pg_namespace') AS nspcomment
            FROM pg_catalog.pg_namespace pn
                LEFT JOIN pg_catalog.pg_roles pu ON (pn.nspowner = pu.oid)
            {$where}
            ORDER BY nspname";

        return $this->connection->selectSet($sql);
    }

    /**
     * Return all information relating to a schema.
     */
    public function getSchemaByName($schema)
    {
        $this->connection->clean($schema);
        $sql = "
            SELECT nspname, nspowner, r.rolname AS ownername, nspacl,
                pg_catalog.obj_description(pn.oid, 'pg_namespace') as nspcomment
            FROM pg_catalog.pg_namespace pn
                LEFT JOIN pg_roles as r ON pn.nspowner = r.oid
            WHERE nspname='{$schema}'";
        return $this->connection->selectSet($sql);
    }

    /**
     * Sets the current working schema.
     */
    public function setSchema($schema)
    {
        $search_path = $this->getSearchPath();
        array_unshift($search_path, $schema);
        $status = $this->setSearchPath($search_path);
        if ($status == 0) {
            $this->connection->_schema = $schema;
            return 0;
        }

        return $status;
    }

    /**
     * Sets the current schema search path.
     */
    public function setSearchPath($paths)
    {
        if (!is_array($paths)) {
            return -1;
        } elseif (sizeof($paths) == 0) {
            return -2;
        } elseif (sizeof($paths) == 1 && $paths[0] == '') {
            $paths[0] = 'pg_catalog';
        }

        $temp = array();
        foreach ($paths as $schema) {
            if ($schema != '') {
                $temp[] = $schema;
            }
        }
        $this->connection->fieldArrayClean($temp);

        $sql = 'SET SEARCH_PATH TO "' . implode('","', $temp) . '"';

        return $this->connection->execute($sql);
    }

    /**
     * Creates a new schema.
     */
    public function createSchema($schemaname, $authorization = '', $comment = '')
    {
        $this->connection->fieldClean($schemaname);
        $this->connection->fieldClean($authorization);

        $sql = "CREATE SCHEMA \"{$schemaname}\"";
        if ($authorization != '') {
            $sql .= " AUTHORIZATION \"{$authorization}\"";
        }

        if ($comment != '') {
            $status = $this->connection->beginTransaction();
            if ($status != 0) {
                return -1;
            }
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($comment != '') {
            $status = $this->connection->setComment('SCHEMA', $schemaname, '', $comment);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }

            return $this->connection->endTransaction();
        }

        return 0;
    }

    /**
     * Updates a schema.
     */
    public function updateSchema($schemaname, $comment, $name, $owner)
    {
        $this->connection->fieldClean($schemaname);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($owner);

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $status = $this->connection->setComment('SCHEMA', $schemaname, '', $comment);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $schema_rs = $this->getSchemaByName($schemaname);
        if ($schema_rs->fields['ownername'] != $owner) {
            $sql = "ALTER SCHEMA \"{$schemaname}\" OWNER TO \"{$owner}\"";
            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        if ($name != $schemaname) {
            $sql = "ALTER SCHEMA \"{$schemaname}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Drops a schema.
     */
    public function dropSchema($schemaname, $cascade)
    {
        $this->connection->fieldClean($schemaname);

        $sql = "DROP SCHEMA \"{$schemaname}\"";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Return the current schema search path.
     */
    public function getSearchPath()
    {
        $sql = 'SELECT current_schemas(false) AS search_path';

        return $this->connection->phpArray($this->connection->selectField($sql, 'search_path'));
    }
}
