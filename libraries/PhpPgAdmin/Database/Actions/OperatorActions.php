<?php

namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Database\AbstractActions;

class OperatorActions extends AbstractActions
{

    /**
     * Gets all operators.
     */
    public function getOperators()
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql = "
            SELECT
                po.oid, po.oprname,
                (SELECT pg_catalog.format_type(oid, NULL) FROM pg_catalog.pg_type pt WHERE pt.oid=po.oprleft) AS oprleftname,
                (SELECT pg_catalog.format_type(oid, NULL) FROM pg_catalog.pg_type pt WHERE pt.oid=po.oprright) AS oprrightname,
                po.oprresult::pg_catalog.regtype AS resultname,
                pg_catalog.obj_description(po.oid, 'pg_operator') AS oprcomment
            FROM
                pg_catalog.pg_operator po
            WHERE
                po.oprnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}')
            ORDER BY
                po.oprname, oprleftname, oprrightname
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns all details for a particular operator.
     */
    public function getOperator($operator_oid)
    {
        $this->connection->clean($operator_oid);

        $sql = "
            SELECT
                po.oid, po.oprname,
                oprleft::pg_catalog.regtype AS oprleftname,
                oprright::pg_catalog.regtype AS oprrightname,
                oprresult::pg_catalog.regtype AS resultname,
                po.oprcanhash,
                oprcanmerge,
                oprcom::pg_catalog.regoperator AS oprcom,
                oprnegate::pg_catalog.regoperator AS oprnegate,
                po.oprcode::pg_catalog.regproc AS oprcode,
                po.oprrest::pg_catalog.regproc AS oprrest,
                po.oprjoin::pg_catalog.regproc AS oprjoin
            FROM
                pg_catalog.pg_operator po
            WHERE
                po.oid='{$operator_oid}'
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Drops an operator.
     */
    public function dropOperator($operator_oid, $cascade)
    {
        $opr = $this->getOperator($operator_oid);
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($opr->fields['oprname']);

        $sql = "DROP OPERATOR \"{$f_schema}\".{$opr->fields['oprname']} (";
        if ($opr->fields['oprleftname'] !== null) {
            $sql .= $opr->fields['oprleftname'] . ', ';
        } else {
            $sql .= "NONE, ";
        }
        if ($opr->fields['oprrightname'] !== null) {
            $sql .= $opr->fields['oprrightname'] . ')';
        } else {
            $sql .= "NONE)";
        }

        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }
}
