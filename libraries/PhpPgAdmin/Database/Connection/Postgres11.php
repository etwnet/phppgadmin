<?php

/**
 * PostgreSQL 11 support
 *
 */

namespace PhpPgAdmin\Database\Connection;


class Postgres11 extends Postgres
{

	var $major_version = 11;

	/**
	 * Returns the current default_with_oids setting
	 * @return default_with_oids setting
	 */
	function getDefaultWithOid()
	{

		$sql = "SHOW default_with_oids";

		return $this->selectField($sql, 'default_with_oids');
	}

	/**
	 * Checks to see whether or not a table has a unique id column
	 * @param string $table The table name
	 * @return bool True if it has a unique id, false otherwise
	 * @return null error
	 **/
	function hasObjectID($table)
	{
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
	function hasServerOids()
	{
		return true;
	}
}
