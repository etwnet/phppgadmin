<?php

namespace PhpPgAdmin\Database\Connection;

use PhpPgAdmin\Database\Connection;

class Postgres extends Connection {
	// PostgreSQL-specific constants and metadata
	public $major_version = 14;
	public $platform = 'PostgreSQL';

	// Max object name length
	public $_maxNameLen = 63;

	// Store the current schema
	public $_schema;

	// PostgreSQL type mapping
	public $codemap = [
		'UNICODE' => 'UTF-8',
		'UTF8' => 'UTF-8',
		'LATIN1' => 'ISO-8859-1',
		// ... etc
	];

	public $defaultprops = ['', '', ''];
	public $extraTypes = ['SERIAL', 'BIGSERIAL'];
	public $fkactions = ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT'];
	public $fkdeferrable = ['NOT DEFERRABLE', 'DEFERRABLE'];
	public $fkinitial = ['INITIALLY IMMEDIATE', 'INITIALLY DEFERRED'];
	public $fkmatches = ['MATCH SIMPLE', 'MATCH FULL'];

	// Function properties
	public $funcprops = [
		['', 'VOLATILE', 'IMMUTABLE', 'STABLE'],
		['', 'SECURITY INVOKER', 'SECURITY DEFINER']
	];

	public $id = 'oid';
	public $joinOps = [
		'INNER JOIN' => 'INNER JOIN',
		'LEFT JOIN' => 'LEFT JOIN',
		'RIGHT JOIN' => 'RIGHT JOIN',
		'FULL JOIN' => 'FULL JOIN'
	];

	public $predefined_size_types = [
		'abstime', 'aclitem', 'bigserial', 'boolean', 'bytea', 'cid', 'cidr', 'circle', 'date',
		'float4', 'float8', 'gtsvector', 'inet', 'int2', 'int4', 'int8', 'macaddr', 'money',
		'oid', 'path', 'polygon', 'refcursor', 'regclass', 'regoper', 'regoperator', 'regproc',
		'regprocedure', 'regtype', 'reltime', 'serial', 'smgr', 'text', 'tid', 'tinterval',
		'tsquery', 'tsvector', 'varbit', 'void', 'xid'
	];

	public $triggerEvents = [
		'INSERT', 'UPDATE', 'DELETE',
		'INSERT OR UPDATE', 'INSERT OR DELETE',
		'DELETE OR UPDATE', 'INSERT OR DELETE OR UPDATE'
	];

	public $triggerExecTimes = ['BEFORE', 'AFTER'];
	public $triggerFrequency = ['ROW', 'STATEMENT'];

	public $typAligns = ['char', 'int2', 'int4', 'double'];
	public $typAlignDef = 'int4';
	public $typIndexDef = 'BTREE';
	public $typIndexes = ['BTREE', 'RTREE', 'GIST', 'GIN', 'HASH'];
	public $typStorages = ['plain', 'external', 'extended', 'main'];
	public $typStorageDef = 'plain';

	// ... other PostgreSQL-specific constants ...

	/**
	 * Postgres constructor.
	 * @param \ADOConnection $conn
	 */
	public function __construct($conn, $version) {
		parent::__construct($conn);
		$this->major_version = (int)$version;
	}

	/**
	 * Sets the comment for an object in the database.
	 * @pre All parameters must already be cleaned
	 * @param string $obj_type
	 * @param string $obj_name
	 * @param string $table
	 * @param string $comment
	 * @param string|null $basetype
	 * @return int 0 on success, -1 on error
	 */
	public function setComment($obj_type, $obj_name, $table, $comment, $basetype = null) {
		$sql = "COMMENT ON {$obj_type} ";
		$f_schema = $this->_schema;
		$this->fieldClean($f_schema);
		$this->clean($comment);

		switch ($obj_type) {
		case 'TABLE':
			$sql .= "\"{$f_schema}\".\"{$table}\" IS ";
			break;
		case 'COLUMN':
			$sql .= "\"{$f_schema}\".\"{$table}\".\"{$obj_name}\" IS ";
			break;
		case 'SEQUENCE':
		case 'VIEW':
		case 'TEXT SEARCH CONFIGURATION':
		case 'TEXT SEARCH DICTIONARY':
		case 'TEXT SEARCH TEMPLATE':
		case 'TEXT SEARCH PARSER':
		case 'TYPE':
			$sql .= "\"{$f_schema}\".";
		case 'DATABASE':
		case 'ROLE':
		case 'SCHEMA':
		case 'TABLESPACE':
			$sql .= "\"{$obj_name}\" IS ";
			break;
		case 'FUNCTION':
			$sql .= "\"{$f_schema}\".{$obj_name} IS ";
			break;
		case 'AGGREGATE':
			$sql .= "\"{$f_schema}\".\"{$obj_name}\" (\"{$basetype}\") IS ";
			break;
		default:
			return -1;
		}

		if ($comment != '') {
			$sql .= "'{$comment}';";
		} else {
			$sql .= 'NULL;';
		}

		return $this->execute($sql);
	}

	/**
	 * Searches all system catalogs to find objects that match a name.
	 */
	public function findObject($term, $filter) {
		global $conf;

		$this->clean($term);
		$this->clean($filter);
		$term = str_replace('_', '\\_', $term);
		$term = str_replace('%', '\\%', $term);

		if (!$conf['show_system']) {
			$where = " AND pn.nspname NOT LIKE \$_PATTERN_\$pg\_%\$_PATTERN_\$ AND pn.nspname != 'information_schema'";
			$lan_where = "AND pl.lanispl";
		} else {
			$where = '';
			$lan_where = '';
		}

		$sql = '';
		if ($filter != '') {
			$sql = "SELECT * FROM (";
		}

		$term = "\$_PATTERN_\$%{$term}%\$_PATTERN_\$";

		$sql .= "
            SELECT 'SCHEMA' AS type, oid, NULL AS schemaname, NULL AS relname, nspname AS name
                FROM pg_catalog.pg_namespace pn WHERE nspname ILIKE {$term} {$where}
            UNION ALL
            SELECT CASE WHEN relkind='r' THEN 'TABLE' WHEN relkind='v' THEN 'VIEW' WHEN relkind='S' THEN 'SEQUENCE' END, pc.oid,
                pn.nspname, NULL, pc.relname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn
                WHERE pc.relnamespace=pn.oid AND relkind IN ('r', 'v', 'S') AND relname ILIKE {$term} {$where}
            UNION ALL
            SELECT CASE WHEN pc.relkind='r' THEN 'COLUMNTABLE' ELSE 'COLUMNVIEW' END, NULL, pn.nspname, pc.relname, pa.attname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_attribute pa WHERE pc.relnamespace=pn.oid AND pc.oid=pa.attrelid
                AND pa.attname ILIKE {$term} AND pa.attnum > 0 AND NOT pa.attisdropped AND pc.relkind IN ('r', 'v') {$where}
            UNION ALL
            SELECT 'FUNCTION', pp.oid, pn.nspname, NULL, pp.proname || '(' || pg_catalog.oidvectortypes(pp.proargtypes) || ')' FROM pg_catalog.pg_proc pp, pg_catalog.pg_namespace pn
                WHERE pp.pronamespace=pn.oid AND NOT pp.prokind = 'a' AND pp.proname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'INDEX', NULL, pn.nspname, pc.relname, pc2.relname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_index pi, pg_catalog.pg_class pc2 WHERE pc.relnamespace=pn.oid AND pc.oid=pi.indrelid
                AND pi.indexrelid=pc2.oid
                AND NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_constraint c
                    ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                    WHERE d.classid = pc2.tableoid AND d.objid = pc2.oid AND d.deptype = 'i' AND c.contype IN ('u', 'p')
                )
                AND pc2.relname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'CONSTRAINTTABLE', NULL, pn.nspname, pc.relname, pc2.conname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_constraint pc2 WHERE pc.relnamespace=pn.oid AND pc.oid=pc2.conrelid AND pc2.conrelid != 0
                AND CASE WHEN pc2.contype IN ('f', 'c') THEN TRUE ELSE NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_constraint c
                    ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                    WHERE d.classid = pc2.tableoid AND d.objid = pc2.oid AND d.deptype = 'i' AND c.contype IN ('u', 'p')
                ) END
                AND pc2.conname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'CONSTRAINTDOMAIN', pt.oid, pn.nspname, pt.typname, pc.conname FROM pg_catalog.pg_type pt, pg_catalog.pg_namespace pn,
                pg_catalog.pg_constraint pc WHERE pt.typnamespace=pn.oid AND pt.oid=pc.contypid AND pc.contypid != 0
                AND pc.conname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'TRIGGER', NULL, pn.nspname, pc.relname, pt.tgname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_trigger pt WHERE pc.relnamespace=pn.oid AND pc.oid=pt.tgrelid
                    AND ( pt.tgconstraint = 0 OR NOT EXISTS
                    (SELECT 1 FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_constraint c
                    ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                    WHERE d.classid = pt.tableoid AND d.objid = pt.oid AND d.deptype = 'i' AND c.contype = 'f'))
                AND pt.tgname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'RULETABLE', NULL, pn.nspname AS schemaname, c.relname AS tablename, r.rulename FROM pg_catalog.pg_rewrite r
                JOIN pg_catalog.pg_class c ON c.oid = r.ev_class
                LEFT JOIN pg_catalog.pg_namespace pn ON pn.oid = c.relnamespace
                WHERE c.relkind='r' AND r.rulename != '_RETURN' AND r.rulename ILIKE {$term} {$where}
            UNION ALL
            SELECT 'RULEVIEW', NULL, pn.nspname AS schemaname, c.relname AS tablename, r.rulename FROM pg_catalog.pg_rewrite r
                JOIN pg_catalog.pg_class c ON c.oid = r.ev_class
                LEFT JOIN pg_catalog.pg_namespace pn ON pn.oid = c.relnamespace
                WHERE c.relkind='v' AND r.rulename != '_RETURN' AND r.rulename ILIKE {$term} {$where}
        ";

		if ($conf['show_advanced']) {
			$sql .= "
                UNION ALL
                SELECT CASE WHEN pt.typtype='d' THEN 'DOMAIN' ELSE 'TYPE' END, pt.oid, pn.nspname, NULL,
                    pt.typname FROM pg_catalog.pg_type pt, pg_catalog.pg_namespace pn
                    WHERE pt.typnamespace=pn.oid AND typname ILIKE {$term}
                    AND (pt.typrelid = 0 OR (SELECT c.relkind = 'c' FROM pg_catalog.pg_class c WHERE c.oid = pt.typrelid))
                    {$where}
                 UNION ALL
                SELECT 'OPERATOR', po.oid, pn.nspname, NULL, po.oprname FROM pg_catalog.pg_operator po, pg_catalog.pg_namespace pn
                    WHERE po.oprnamespace=pn.oid AND oprname ILIKE {$term} {$where}
                UNION ALL
                SELECT 'CONVERSION', pc.oid, pn.nspname, NULL, pc.conname FROM pg_catalog.pg_conversion pc,
                    pg_catalog.pg_namespace pn WHERE pc.connamespace=pn.oid AND conname ILIKE {$term} {$where}
                UNION ALL
                SELECT 'LANGUAGE', pl.oid, NULL, NULL, pl.lanname FROM pg_catalog.pg_language pl
                    WHERE lanname ILIKE {$term} {$lan_where}
                UNION ALL
                SELECT DISTINCT ON (p.proname) 'AGGREGATE', p.oid, pn.nspname, NULL, p.proname FROM pg_catalog.pg_proc p
                    LEFT JOIN pg_catalog.pg_namespace pn ON p.pronamespace=pn.oid
                    WHERE p.prokind = 'a' AND p.proname ILIKE {$term} {$where}
                UNION ALL
                SELECT DISTINCT ON (po.opcname) 'OPCLASS', po.oid, pn.nspname, NULL, po.opcname FROM pg_catalog.pg_opclass po,
                    pg_catalog.pg_namespace pn WHERE po.opcnamespace=pn.oid
                    AND po.opcname ILIKE {$term} {$where}
            ";
		} else {
			$sql .= "
                UNION ALL
                SELECT 'DOMAIN', pt.oid, pn.nspname, NULL,
                    pt.typname FROM pg_catalog.pg_type pt, pg_catalog.pg_namespace pn
                    WHERE pt.typnamespace=pn.oid AND pt.typtype='d' AND typname ILIKE {$term}
                    AND (pt.typrelid = 0 OR (SELECT c.relkind = 'c' FROM pg_catalog.pg_class c WHERE c.oid = pt.typrelid))
                    {$where}
            ";
		}

		if ($filter != '') {
			$sql .= ") AS sub WHERE type LIKE '{$filter}%' ";
		}

		$sql .= "ORDER BY type, schemaname, relname, name";

		return $this->selectSet($sql);
	}

	/**
	 * Returns prepared transactions information.
	 */
	public function getPreparedXacts($database = null) {
		if ($database === null) {
			$sql = "SELECT * FROM pg_prepared_xacts";
		} else {
			$this->clean($database);
			$sql = "SELECT transaction, gid, prepared, owner FROM pg_prepared_xacts
                WHERE database='{$database}' ORDER BY owner";
		}

		return $this->selectSet($sql);
	}


	/**
	 * Returns all available variable information.
	 */
	public function getVariables() {
		$sql = "SHOW ALL";
		return $this->selectSet($sql);
	}

	/**
	 * Returns all available process information.
	 * @param string $database (optional) Find only connections to specified database
	 * @return \ADORecordSet A recordset
	 */
	function getProcesses($database = null) {
		if ($database === null)
			$sql = "SELECT datname, usename, pid, 
                    case when wait_event is null then 'false' else wait_event_type || '::' || wait_event end as waiting, 
                    query_start, application_name, client_addr, 
                  case when state='idle in transaction' then '<IDLE> in transaction' when state = 'idle' then '<IDLE>' else query end as query 
				FROM pg_catalog.pg_stat_activity
				ORDER BY datname, usename, pid";
		else {
			$this->clean($database);
			$sql = "SELECT datname, usename, pid, 
                    case when wait_event is null then 'false' else wait_event_type || '::' || wait_event end as waiting, 
                    query_start, application_name, client_addr, 
                  case when state='idle in transaction' then '<IDLE> in transaction' when state = 'idle' then '<IDLE>' else query end as query 
				FROM pg_catalog.pg_stat_activity
				WHERE datname='{$database}'
				ORDER BY usename, pid";
		}

		return $this->selectSet($sql);
	}


	/**
	 * Determines whether or not a user/role is a super user
	 * @param string $username The username/rolename
	 * @return bool True if is a super user, false otherwise
	 */
	public function isSuperUser($username = '') {
		$this->clean($username);

		if (empty($username)) {
			// Try to get from connection parameter
			$val = pg_parameter_status($this->conn->_connectionID, 'is_superuser');
			if ($val !== false) return $val == 'on';
		}

		$sql = "SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname='{$username}'";

		$rolsuper = $this->selectField($sql, 'rolsuper');
		if ($rolsuper == -1) return false;
		else return $rolsuper == 't';
	}

	// Capabilities

	/**
	 * Checks to see whether or not a table has a unique id column
	 * @param string $table The table name
	 * @return bool True if it has a unique id, false otherwise
	 * @return null error
	 **/
	function hasObjectID($table) {
		// OID support is gone since PG12
		// But that function is required by table exports
		return false;
	}

	function hasAggregateSortOp() {
		return true;
	}

	function hasAlterAggregate() {
		return true;
	}

	function hasAlterColumnType() {
		return true;
	}

	function hasAlterDatabaseOwner() {
		return true;
	}

	function hasAlterDatabaseRename() {
		return true;
	}

	function hasAlterSchema() {
		return true;
	}

	function hasAlterSchemaOwner() {
		return true;
	}

	function hasAlterSequenceSchema() {
		return true;
	}

	function hasAlterSequenceStart() {
		return true;
	}

	function hasAlterTableSchema() {
		return true;
	}

	function hasAutovacuum() {
		return true;
	}

	function hasCreateTableLike() {
		return true;
	}

	function hasCreateTableLikeWithConstraints() {
		return true;
	}

	function hasCreateTableLikeWithIndexes() {
		return true;
	}

	function hasCreateFieldWithConstraints() {
		return true;
	}

	function hasDisableTriggers() {
		return true;
	}

	function hasAlterDomains() {
		return true;
	}

	function hasDomainConstraints() {
		return true;
	}

	function hasEnumTypes() {
		return true;
	}

	function hasFTS() {
		return true;
	}

	function hasFunctionAlterOwner() {
		return true;
	}

	function hasFunctionAlterSchema() {
		return true;
	}

	function hasFunctionCosting() {
		return true;
	}

	function hasFunctionGUC() {
		return true;
	}

	function hasGrantOption() {
		return true;
	}

	function hasNamedParams() {
		return true;
	}

	function hasPrepare() {
		return true;
	}

	function hasPreparedXacts() {
		return true;
	}

	function hasReadOnlyQueries() {
		return true;
	}

	function hasRecluster() {
		return true;
	}

	function hasRoles() {
		return true;
	}

	function hasServerAdminFuncs() {
		return true;
	}

	function hasSharedComments() {
		return true;
	}

	function hasQueryCancel() {
		return true;
	}

	function hasTablespaces() {
		return true;
	}

	function hasUserRename() {
		return true;
	}

	function hasUserSignals() {
		return true;
	}

	function hasVirtualTransactionId() {
		return true;
	}

	function hasAlterDatabase() {
		return $this->hasAlterDatabaseRename();
	}

	function hasDatabaseCollation() {
		return true;
	}

	function hasMagicTypes() {
		return true;
	}

	function hasQueryKill() {
		return true;
	}

	function hasConcurrentIndexBuild() {
		return true;
	}

	function hasForceReindex() {
		return false;
	}

	function hasByteaHexDefault() {
		return true;
	}

	function hasServerOids() {
		return false;
	}
}
