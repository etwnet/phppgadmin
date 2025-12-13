<?php

/**
 * PostgreSQL 9.3 support
 *
 */

namespace PhpPgAdmin\Database\Connection;


class Postgres93 extends Postgres94 {

	var $major_version = 9.3;

	// Help functions

	function getHelpPages() {
		include_once('./help/PostgresDoc93.php');
		return $this->help_page;
	}

}

