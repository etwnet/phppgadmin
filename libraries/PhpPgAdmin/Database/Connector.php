<?php


namespace PhpPgAdmin\Database;


use ADOConnection;

class Connector
{

	/**
	 * @var ADOConnection
	 */
	var $conn;

	// The backend platform.  Set to UNKNOWN by default.
	var $platform = 'UNKNOWN';

	/**
	 * Creates a new connection.  Will actually make a database connection.
	 * @param $fetchMode int Defaults to associative.  Override for different behaviour
	 */
	function __construct(
		$host,
		$port,
		$sslmode,
		$user,
		$password,
		$database,
		$fetchMode = ADODB_FETCH_ASSOC
	) {
		$this->conn = ADONewConnection('postgres8');
		$this->conn->setFetchMode($fetchMode);

		// Ignore host if null
		if ($host === null || $host == '')
			if ($port !== null && $port != '')
				$pghost = ':' . $port;
			else
				$pghost = '';
		else
			$pghost = "{$host}:{$port}";

		// Add sslmode to $pghost as needed
		if (($sslmode == 'disable') || ($sslmode == 'allow') || ($sslmode == 'prefer') || ($sslmode == 'require')) {
			$pghost .= ':' . $sslmode;
		} elseif ($sslmode == 'legacy') {
			$pghost .= ' requiressl=1';
		}

		$this->conn->connect($pghost, $user, $password, $database);
	}

	/**
	 * Gets the name of the correct database driver to use.  As a side effect,
	 * sets the platform.
	 * @param (return-by-ref) $description A description of the database and version
	 * @return string The class name of the driver eg. Postgres84
	 * @return null if version is < 8
	 * @return -3 Database-specific failure
	 */
	function getDriver(&$description, &$version, &$majorVersion)
	{
		global $postgresqlMinVer;

		$v = pg_version($this->conn->_connectionID);
		if (isset($v['server'])) $version = $v['server'];

		// If we didn't manage to get the version without a query, query...
		if (!isset($version)) {

			$rs = $this->conn->Execute("SELECT VERSION() AS version");
			$field = $rs->fields['version'];

			// Check the platform, if it's mingw, set it
			if (preg_match('/ mingw /i', $field))
				$this->platform = 'MINGW';

			$params = explode(' ', $field);
			if (!isset($params[1])) return -3;

			$version = $params[1]; // eg. 18.1
		}

		$majorVersion = (float)substr($version, 0, 3);

		if ($majorVersion < $postgresqlMinVer)
			return null;

		$description = "PostgreSQL {$version}";

		// All versions now use the single unified Postgres class
		// Version-specific logic is handled internally with version checks
		return 'Postgres';
	}

	/**
	 * Get the last error in the connection
	 * @return string Error string
	 */
	function getLastError()
	{
		return pg_last_error($this->conn->_connectionID);
	}
}
