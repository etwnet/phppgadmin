<?php

namespace PhpPgAdmin\Database;

class Postgres extends AbstractConnection
{
	// PostgreSQL-specific constants and metadata
	public $major_version = 0.0;
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
		'abstime',
		'aclitem',
		'bigserial',
		'boolean',
		'bytea',
		'cid',
		'cidr',
		'circle',
		'date',
		'float4',
		'float8',
		'gtsvector',
		'inet',
		'int2',
		'int4',
		'int8',
		'macaddr',
		'money',
		'oid',
		'path',
		'polygon',
		'refcursor',
		'regclass',
		'regoper',
		'regoperator',
		'regproc',
		'regprocedure',
		'regtype',
		'reltime',
		'serial',
		'smgr',
		'text',
		'tid',
		'tinterval',
		'tsquery',
		'tsvector',
		'varbit',
		'void',
		'xid'
	];

	public $triggerEvents = [
		'INSERT',
		'UPDATE',
		'DELETE',
		'INSERT OR UPDATE',
		'INSERT OR DELETE',
		'DELETE OR UPDATE',
		'INSERT OR DELETE OR UPDATE'
	];

	public $triggerExecTimes = ['BEFORE', 'AFTER'];
	public $triggerFrequency = ['ROW', 'STATEMENT'];

	public $typAligns = ['char', 'int2', 'int4', 'double'];
	public $typAlignDef = 'int4';
	public $typIndexDef = 'BTREE';
	public $typIndexes = ['BTREE', 'RTREE', 'GIST', 'GIN', 'HASH'];
	public $typStorages = ['plain', 'external', 'extended', 'main'];
	public $typStorageDef = 'plain';

	// Select operators
	public $selectOps = [
		'=' => 'i',
		'!=' => 'i',
		'<' => 'i',
		'>' => 'i',
		'<=' => 'i',
		'>=' => 'i',
		'<<' => 'i',
		'>>' => 'i',
		'<<=' => 'i',
		'>>=' => 'i',
		'LIKE' => 'i',
		'NOT LIKE' => 'i',
		'ILIKE' => 'i',
		'NOT ILIKE' => 'i',
		'SIMILAR TO' => 'i',
		'NOT SIMILAR TO' => 'i',
		'~' => 'i',
		'!~' => 'i',
		'~*' => 'i',
		'!~*' => 'i',
		'IS NULL' => 'p',
		'IS NOT NULL' => 'p',
		'IN' => 'x',
		'NOT IN' => 'x',
		'@@' => 'i',
		'@@@' => 'i',
		'@>' => 'i',
		'<@' => 'i',
		'@@ to_tsquery' => 't',
		'@@@ to_tsquery' => 't',
		'@> to_tsquery' => 't',
		'<@ to_tsquery' => 't',
		'@@ plainto_tsquery' => 't',
		'@@@ plainto_tsquery' => 't',
		'@> plainto_tsquery' => 't',
		'<@ plainto_tsquery' => 't'
	];

	/**
	 * Postgres constructor.
	 * @param \ADOConnection $conn
	 */
	public function __construct($conn, $majorVersion)
	{
		parent::__construct($conn);
		$this->major_version = $majorVersion;
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
	public function setComment($obj_type, $obj_name, $table, $comment, $basetype = null)
	{
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
	public function findObject($term, $filter)
	{
		$conf = $this->conf();

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
                WHERE pp.pronamespace=pn.oid AND NOT (CASE WHEN (SELECT count(*) FROM pg_catalog.pg_proc WHERE prokind IS NOT NULL) > 0 THEN pp.prokind = 'a' ELSE pp.proisagg END) AND pp.proname ILIKE {$term} {$where}
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
	public function getPreparedXacts($database = null)
	{
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
	public function getVariables()
	{
		$sql = "SHOW ALL";
		return $this->selectSet($sql);
	}

	/**
	 * Returns all available process information.
	 * @param string $database (optional) Find only connections to specified database
	 * @return \ADORecordSet A recordset
	 */
	function getProcesses($database = null)
	{
		// Different query for PostgreSQL versions < 9.5
		if ((float)$this->major_version < 9.5) {
			// PostgreSQL 9.1-9.4 format with procpid and current_query
			if ($database === null)
				$sql = "SELECT datname, usename, procpid AS pid, waiting, current_query AS query, query_start
					FROM pg_catalog.pg_stat_activity
					ORDER BY datname, usename, procpid";
			else {
				$this->clean($database);
				$sql = "SELECT datname, usename, procpid AS pid, waiting, current_query AS query, query_start
					FROM pg_catalog.pg_stat_activity
					WHERE datname='{$database}'
					ORDER BY usename, procpid";
			}
		} else {
			// PostgreSQL 9.5+ format with wait_event and state
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
		}

		return $this->selectSet($sql);
	}

	/**
	 * Retrieves all tablespace information.
	 * @param bool $all Include all tablespaces (necessary when moving objects back to the default space)
	 * @return \ADORecordSet A recordset
	 */
	function getTablespaces($all = false)
	{
		$conf = $this->conf();

		$sql = "SELECT spcname, pg_catalog.pg_get_userbyid(spcowner) AS spcowner, spclocation,
                    (SELECT description FROM pg_catalog.pg_shdescription pd WHERE pg_tablespace.oid=pd.objoid AND pd.classoid='pg_tablespace'::regclass) AS spccomment
				FROM pg_catalog.pg_tablespace";

		if (!$conf['show_system'] && !$all) {
			$sql .= ' WHERE spcname NOT LIKE $$pg\_%$$';
		}

		$sql .= " ORDER BY spcname";

		return $this->selectSet($sql);
	}

	/**
	 * Retrieves a specific tablespace's information.
	 * @param string $spcname Tablespace name
	 * @return \ADORecordSet A recordset
	 */
	function getTablespace($spcname)
	{
		$this->clean($spcname);

		$sql = "SELECT spcname, pg_catalog.pg_get_userbyid(spcowner) AS spcowner, spclocation,
                    (SELECT description FROM pg_catalog.pg_shdescription pd WHERE pg_tablespace.oid=pd.objoid AND pd.classoid='pg_tablespace'::regclass) AS spccomment
				FROM pg_catalog.pg_tablespace WHERE spcname='{$spcname}'";

		return $this->selectSet($sql);
	}

	/**
	 * Determines whether or not a user/role is a super user
	 * @param string $username The username/rolename
	 * @return bool True if is a super user, false otherwise
	 */
	public function isSuperUser($username = '')
	{
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

	// Help pages

	// Default help URL
	var $help_base;
	// Help sub pages
	var $help_page;

	/**
	 * Fetch a URL (or array of URLs) for a given help page.
	 */
	function getHelp($help)
	{
		$this->getHelpPages();

		if (isset($this->help_page[$help])) {
			if (is_array($this->help_page[$help])) {
				$urls = array();
				foreach ($this->help_page[$help] as $link) {
					$urls[] = $this->help_base . $link;
				}
				return $urls;
			} else
				return $this->help_base . $this->help_page[$help];
		} else
			return null;
	}

	/**
	 * Returns the help pages for this PostgreSQL version.
	 * @return array The help pages
	 */
	function getHelpPages()
	{
		if (!isset($this->help_page)) {
			// Determine the version-specific help file to include
			$version = $this->major_version;

			include './help/PostgresDocBase.php';

			$conf = $this->conf();
			$this->help_base = sprintf($conf['help_base'], (string)$version);
		}

		return $this->help_page;
	}

	/**
	 * Generates the SQL for the 'select' function
	 * @param $table string The table from which to select
	 * @param $show array An array of columns to show.  Empty array means all columns.
	 * @param $values array An array mapping columns to values
	 * @param $ops array An array of the operators to use
	 * @param $orderby array (optional) An array of column numbers or names (one based)
	 *        mapped to sort direction (asc or desc or '' or null) to order by
	 * @return string The SQL query
	 */
	function getSelectSQL($table, $show, $values, $ops, $orderby = array())
	{
		$this->fieldArrayClean($show);

		// If an empty array is passed in, then show all columns
		if (sizeof($show) == 0) {
			if ($this->hasObjectID($table))
				$sql = "SELECT \"{$this->id}\", * FROM ";
			else
				$sql = "SELECT * FROM ";
		} else {
			// Add oid column automatically to results for editing purposes
			if (!in_array($this->id, $show) && $this->hasObjectID($table))
				$sql = "SELECT \"{$this->id}\", \"";
			else
				$sql = "SELECT \"";

			$sql .= join('","', $show) . "\" FROM ";
		}

		$this->fieldClean($table);

		if (isset($_REQUEST['schema'])) {
			$f_schema = $_REQUEST['schema'];
			$this->fieldClean($f_schema);
			$sql .= "\"{$f_schema}\".";
		}
		$sql .= "\"{$table}\"";

		// If we have values specified, add them to the WHERE clause
		$first = true;
		if (is_array($values) && sizeof($values) > 0) {
			foreach ($values as $k => $v) {
				if ($v != '' || $this->selectOps[$ops[$k]] == 'p') {
					$this->fieldClean($k);
					if ($first) {
						$sql .= " WHERE ";
						$first = false;
					} else {
						$sql .= " AND ";
					}
					// Different query format depending on operator type
					switch ($this->selectOps[$ops[$k]]) {
						case 'i':
							// Only clean the field for the inline case
							// this is because (x), subqueries need to
							// to allow 'a','b' as input.
							$this->clean($v);
							$sql .= "\"{$k}\" {$ops[$k]} '{$v}'";
							break;
						case 'p':
							$sql .= "\"{$k}\" {$ops[$k]}";
							break;
						case 'x':
							$sql .= "\"{$k}\" {$ops[$k]} ({$v})";
							break;
						case 't':
							$sql .= "\"{$k}\" {$ops[$k]}('{$v}')";
							break;
						default:
							// Shouldn't happen
					}
				}
			}
		}

		// ORDER BY
		if (is_array($orderby) && sizeof($orderby) > 0) {
			$sql .= " ORDER BY ";
			$sep = "";
			foreach ($orderby as $k => $v) {
				$sql .= $sep;
				$sep = ", ";
				if (preg_match('/^[0-9]+$/', $k)) {
					$sql .= $k;
				} else {
					$sql .= '"' . $this->fieldClean($k) . '"';
				}
				if (strtoupper($v) == 'DESC') $sql .= " DESC";
			}
		}

		return $sql;
	}

	/**
	 * Checks to see whether or not a table has a unique id column
	 * @param string $table The table name
	 * @return bool True if it has a unique id, false otherwise
	 * @return null error
	 **/
	function hasObjectID($table)
	{
		if ($this->major_version > 11) {
			return false;
		}
		$c_schema = $this->_schema;
		$this->clean($c_schema);
		$this->clean($table);

		$sql = "SELECT relhasoids FROM pg_catalog.pg_class WHERE relname='{$table}'
			AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}')";

		$rs = $this->selectSet($sql);
		if ($rs->recordCount() != 1) return null;
		else {
			$rs->fields['relhasoids'] = $this->phpBool($rs->fields['relhasoids']);
			return $rs->fields['relhasoids'];
		}
	}


	// Capabilities

	function hasAlterSequenceSchema()
	{
		return true;
	}

	function hasAlterSequenceStart()
	{
		return true;
	}

	function hasAlterTableSchema()
	{
		return true;
	}

	function hasAutovacuum()
	{
		return true;
	}

	function hasCreateTableLike()
	{
		return true;
	}

	function hasCreateTableLikeWithConstraints()
	{
		return true;
	}

	function hasCreateTableLikeWithIndexes()
	{
		return true;
	}

	function hasCreateFieldWithConstraints()
	{
		return true;
	}

	function hasDisableTriggers()
	{
		return true;
	}

	function hasAlterDomains()
	{
		return true;
	}

	function hasDomainConstraints()
	{
		return true;
	}

	function hasEnumTypes()
	{
		return true;
	}

	function hasFTS()
	{
		return true;
	}

	function hasFunctionAlterOwner()
	{
		return true;
	}

	function hasFunctionAlterSchema()
	{
		return true;
	}

	function hasFunctionCosting()
	{
		return true;
	}

	function hasFunctionGUC()
	{
		return true;
	}

	function hasGrantOption()
	{
		return true;
	}

	function hasNamedParams()
	{
		return true;
	}

	function hasPrepare()
	{
		return true;
	}

	function hasPreparedXacts()
	{
		return true;
	}

	function hasReadOnlyQueries()
	{
		return true;
	}

	function hasRecluster()
	{
		return true;
	}

	function hasRoles()
	{
		return true;
	}

	function hasServerAdminFuncs()
	{
		return true;
	}

	function hasSharedComments()
	{
		return true;
	}

	function hasQueryCancel()
	{
		return true;
	}

	function hasTablespaces()
	{
		return true;
	}

	function hasUserRename()
	{
		return true;
	}

	function hasUserSignals()
	{
		// PostgreSQL versions 9.0-9.4 do not have user signals capability
		return $this->major_version >= 9.5;
	}

	function hasVirtualTransactionId()
	{
		return true;
	}

	function hasAlterDatabase()
	{
		return true;
	}

	function hasDatabaseCollation()
	{
		return true;
	}

	function hasMagicTypes()
	{
		return true;
	}

	function hasQueryKill()
	{
		return true;
	}

	function hasConcurrentIndexBuild()
	{
		return true;
	}

	function hasForceReindex()
	{
		return false;
	}

	function hasByteaHexDefault()
	{
		return true;
	}

	function hasServerOids()
	{
		// Server OIDs are available only until PostgreSQL 11
		return $this->major_version <= 11;
	}
}
