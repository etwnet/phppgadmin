<?php

/**
 * PostgreSQL 9.6 support
 *
 */

namespace PhpPgAdmin\Database\Connection;


class Postgres96 extends Postgres10 {

	var $major_version = 9.6;

	/**
	 * Constructor
	 * @param \ADOConnection $conn The database connection
	 */
    function __construct($conn) {
        parent::__construct($conn);
    }

	// Help functions

	function getHelpPages() {
		include_once('./help/PostgresDoc96.php');
		return $this->help_page;
	}

	// Sequence functions

	/**
	 * Returns properties of a single sequence
	 * @param string $sequence Sequence name
	 * @return \ADORecordSet A recordset
	 */
	function getSequence($sequence) {
		$c_schema = $this->_schema;
		$this->clean($c_schema);
		$c_sequence = $sequence;
		$this->fieldClean($sequence);
		$this->clean($c_sequence);

		$sql = "
			SELECT c.relname AS seqname, s.*,
				pg_catalog.obj_description(s.tableoid, 'pg_class') AS seqcomment,
				u.usename AS seqowner, n.nspname
			FROM \"{$sequence}\" AS s, pg_catalog.pg_class c, pg_catalog.pg_user u, pg_catalog.pg_namespace n
			WHERE c.relowner=u.usesysid AND c.relnamespace=n.oid
				AND c.relname = '{$c_sequence}' AND c.relkind = 'S' AND n.nspname='{$c_schema}'
				AND n.oid = c.relnamespace";

		return $this->selectSet( $sql );
	}


}

