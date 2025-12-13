<?php

/**
 * PostgreSQL 13 support
 *
 */

namespace PhpPgAdmin\Database\Connection;


class Postgres13 extends Postgres {

	var $major_version = 13;

	// Help functions

	function getHelpPages() {
		include_once('./help/PostgresDoc13.php');
		return $this->help_page;
	}


	// Capabilities

}

