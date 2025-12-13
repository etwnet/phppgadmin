<?php

/**
 * PostgreSQL 9.4 support
 *
 */

namespace PhpPgAdmin\Database\Connection;


class Postgres94 extends Postgres95 {

	var $major_version = 9.4;

	// Help functions

	function getHelpPages() {
		include_once('./help/PostgresDoc94.php');
		return $this->help_page;
	}

}

