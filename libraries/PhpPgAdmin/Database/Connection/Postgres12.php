<?php

/**
 * PostgreSQL 12 support
 *
 */

namespace PhpPgAdmin\Database\Connection;


class Postgres12 extends Postgres13 {

	var $major_version = 12;

	// Help functions

	function getHelpPages() {
		include_once('./help/PostgresDoc12.php');
		return $this->help_page;
	}


	// Capabilities

}

