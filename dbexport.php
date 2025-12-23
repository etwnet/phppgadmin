<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\DumpManager;
use PhpPgAdmin\Database\Dump\DumpFactory;

/**
 * Does an export of a database, schema, or table (via pg_dump or php fallback)
 * to the screen or as a download.
 * Uses DumpManager for executable detection and format validation.
 *
 * $Id: dbexport.php,v 1.22 2007/03/25 03:15:09 xzilla Exp $
 */

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

include_once('./libraries/bootstrap.php');

// Include application functions
AppContainer::setSkipHtmlFrame(true);
$f_schema = $f_object = '';

// Are we doing a cluster-wide dump or just a per-database dump
$dumpall = $_REQUEST['subject'] == 'server';

$output_method = $_REQUEST['output'] ?? 'show';

// Generate timestamp for download filename
$timestamp = date('Ymd_His');
$filename_base = 'dump_' . $timestamp;

// Add context to filename (database/schema/table and export type)
$filename_parts = [];

// Add database name if available
if (isset($_REQUEST['database']) && !$dumpall) {
	$filename_parts[] = $_REQUEST['database'];
}

// Add schema name if available
if (isset($_REQUEST['schema'])) {
	$filename_parts[] = $_REQUEST['schema'];
}

// Add table or view name if available
if (isset($_REQUEST['table'])) {
	$filename_parts[] = $_REQUEST['table'];
} elseif (isset($_REQUEST['view'])) {
	$filename_parts[] = $_REQUEST['view'];
}

// Add export type shorthand
$what_map = [
	'dataonly' => 'data',
	'structureonly' => 'struct',
	'structureanddata' => 'full'
];
$what_short = $what_map[$_REQUEST['what']] ?? 'export';
$filename_parts[] = $what_short;

// Build final filename
if (!empty($filename_parts)) {
	$filename_base .= '_' . implode('_', array_map(
		function ($v) {
			return preg_replace('/[^a-zA-Z0-9_-]/', '', $v); },
		$filename_parts
	));
}

// Detect pg_dump/pg_dumpall executable with automatic fallback
$exe_path = DumpManager::getDumpExecutable($dumpall);

$pg = AppContainer::getPostgres();

if (empty($exe_path)) {
	// pg_dump not available - use internal dumper
	$subject = $_REQUEST['subject'] ?? 'database';
	$params = [
		'table' => $_REQUEST['table'] ?? null,
		'view' => $_REQUEST['view'] ?? null,
		'schema' => $_REQUEST['schema'] ?? null,
		'database' => $_REQUEST['database'] ?? null
	];
	$options = [
		'format' => $_REQUEST['d_format'] ?? 'sql',
		'clean' => isset($_REQUEST['s_clean']) || isset($_REQUEST['sd_clean']),
		'if_not_exists' => isset($_REQUEST['if_not_exists']),
		'structure_only' => ($_REQUEST['what'] === 'structureonly'),
		'data_only' => ($_REQUEST['what'] === 'dataonly')
	];

	if ($output_method === 'download') {
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql');
	} else {
		header('Content-Type: text/html; charset=utf-8');
		?>
		<style>
			.export-controls {
				margin-bottom: 15px;
			}

			.export-controls a {
				margin-right: 15px;
				padding: 5px 10px;
				background: #f0f0f0;
				border: 1px solid #ccc;
				text-decoration: none;
				border-radius: 3px;
			}

			.export-controls a:hover {
				background: #e0e0e0;
			}

			textarea {
				width: 100%;
				height: calc(100vh - 120px);
				border: 1px solid #ccc;
				font-family: monospace;
				padding: 10px;
				box-sizing: border-box;
			}
		</style>
		<div class="export-controls">
			<a href="javascript:history.back()">← Back</a>
			<a href="javascript:location.reload()">↻ Reload</a>
		</div>
		<textarea readonly><?php
	}

	$dumper = DumpFactory::create($subject, $pg);
	$dumper->dump($subject, $params, $options);

	if ($output_method !== 'download') {
		echo "</textarea>";
	}
	exit;
}

$exe = $misc->escapeShellCmd($exe_path);

// Obtain the pg_dump version number and check if the path is good
$version = [];
preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", exec($exe . " --version"), $version);

if (empty($version)) {
	echo "Error: Could not execute " . htmlspecialchars($exe_path) . "\n";
	echo "The executable exists but could not be run. Please check permissions.\n";
	exit;
}

// Get server connection info for version and connection checks
$server_info = $misc->getServerInfo();

// Check if pg_dump version is older than server version
$version_mismatch = false;
if (!empty($server_info['pgVersion'])) {
	$dump_version = floatval($version[1]);
	$server_version = floatval($server_info['pgVersion']);

	if ($dump_version < $server_version) {
		$version_mismatch = true;
	}
}

// Determine if we should use pg_dump or fallback to psql
$use_pg_dump = !$version_mismatch && !$dumpall;
$use_psql_fallback = $version_mismatch && $_REQUEST['what'] == 'dataonly';

// Validate version mismatch scenario
if ($version_mismatch && $_REQUEST['what'] != 'dataonly') {
	echo "Error: pg_dump version mismatch makes structure-only dumps unreliable.\n";
	echo "Only data-only exports are available with version mismatches.\n";
	echo "Please upgrade pg_dump to match your server version or select data-only export.\n";
	exit;
}

// Validate psql availability if needed
if ($use_psql_fallback) {
	$psql_path = DumpManager::getPsqlExecutable();
	if (empty($psql_path)) {
		echo "Error: psql executable not found.\n";
		echo "psql is required for fallback data exports when pg_dump version doesn't match.\n";
		echo "Please ensure PostgreSQL client tools are installed.\n";
		exit;
	}
}

// All validation passed - set headers and output HTML
switch ($output_method) {
	case 'show':
		header('Content-Type: text/html; charset=utf-8');
		break;
	case 'download':
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql');
		break;
	case 'gzipped':
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql.gz');
		break;
}

// For show mode, start HTML output with textarea and links
if ($output_method === 'show') {
	?>
		<style>
			/*
												body {
													font-family: monospace;
													margin: 10px;
												}
												*/

			.export-controls {
				margin-bottom: 15px;
			}

			.export-controls a {
				margin-right: 15px;
				padding: 5px 10px;
				background: #f0f0f0;
				border: 1px solid #ccc;
				text-decoration: none;
				border-radius: 3px;
			}

			.export-controls a:hover {
				background: #e0e0e0;
			}

			textarea {
				width: 100%;
				height: calc(100vh - 120px);
				border: 1px solid #ccc;
				font-family: monospace;
				/*font-size: 12px;*/
				padding: 10px;
				box-sizing: border-box;
			}
		</style>
		<div class="export-controls">
			<a href="javascript:history.back()">← Back</a>
			<a href="javascript:location.reload()">↻ Reload</a>
		</div>
		<?php
		echo "<textarea readonly>";
}

// Output dump header with version info
echo "-- Dumping with " . htmlspecialchars($exe_path) . " version " . $version[1] . "\n\n";

// Output version warning if applicable
if ($version_mismatch) {
	$dump_version = floatval($version[1]);
	$server_version = floatval($server_info['pgVersion']);
	echo "-- WARNING: pg_dump version ($dump_version) is older than PostgreSQL server version ($server_version)\n";
	echo "-- Using alternative export method (psql COPY). Some advanced features may be limited.\n\n";
}

// Set environmental variables that pg_dump uses
putenv('PGPASSWORD=' . $server_info['password']);
putenv('PGUSER=' . $server_info['username']);
$hostname = $server_info['host'];
if ($hostname !== null && $hostname != '') {
	putenv('PGHOST=' . $hostname);
}
$port = $server_info['port'];
if ($port !== null && $port != '') {
	putenv('PGPORT=' . $port);
}

// Build command using appropriate export method
if ($use_psql_fallback) {
	// Use psql + COPY for data-only exports when pg_dump version doesn't match
	echo "-- Using psql COPY method for data export\n\n";

	// Detect psql executable using DumpManager (already validated above)
	$psql_path = DumpManager::getPsqlExecutable();

	$psql_exe = $misc->escapeShellCmd($psql_path);
	$cmd = $psql_exe;

	// Add connection parameters
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		$cmd .= ' -h ' . $misc->escapeShellArg($server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		$cmd .= ' -p ' . intval($server_info['port']);
	}
	if ($server_info['username'] !== null && $server_info['username'] !== '') {
		$cmd .= ' -U ' . $misc->escapeShellArg($server_info['username']);
	}
	if (!$dumpall && isset($_REQUEST['database'])) {
		$cmd .= ' -d ' . $misc->escapeShellArg($_REQUEST['database']);
	}

	// Build COPY commands for each table
	$cmd .= ' -t '; // Tuple-only mode (no headers)

	// Get tables to export
	switch ($_REQUEST['subject']) {
		case 'table':
		case 'view':
			$f_object = $_REQUEST[$_REQUEST['subject']];
			$data->fieldClean($f_object);
			if (isset($_REQUEST['schema'])) {
				$f_schema = $_REQUEST['schema'];
				$data->fieldClean($f_schema);
				$cmd .= ' -c ' . $misc->escapeShellArg('COPY "' . $f_schema . '"."' . $f_object . '" TO STDOUT WITH CSV HEADER');
			}
			break;
		case 'schema':
			// For schema export, dump all tables in that schema
			if (isset($_REQUEST['schema'])) {
				$f_schema = $_REQUEST['schema'];
				$data->fieldClean($f_schema);
				$cmd .= ' -c ' . $misc->escapeShellArg('SELECT tablename FROM pg_tables WHERE schemaname = \'' . $f_schema . '\' ORDER BY tablename');
			}
			break;
	}

} else {
	// Use pg_dump normally
	$cmd = $exe;

	// Add explicit host if specified (prevents defaulting to Unix socket)
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		$cmd .= ' -h ' . $misc->escapeShellArg($server_info['host']);
	}

	// Add explicit port if specified (non-default)
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		$cmd .= ' -p ' . intval($server_info['port']);
	}

	// Add explicit username
	if ($server_info['username'] !== null && $server_info['username'] !== '') {
		$cmd .= ' -U ' . $misc->escapeShellArg($server_info['username']);
	}

	// we are PG 9.0+, so we always have a schema
	if (isset($_REQUEST['schema'])) {
		$f_schema = $_REQUEST['schema'];
		$data->fieldClean($f_schema);
	}

	// Check for a specified table/view
	switch ($_REQUEST['subject']) {
		case 'schema':
			// This currently works for 8.2+ (due to the orthoganl -t -n issue introduced then)
			$cmd .= " -n " . $misc->escapeShellArg("\"{$f_schema}\"");
			break;
		case 'table':
		case 'view':
			$f_object = $_REQUEST[$_REQUEST['subject']];
			$data->fieldClean($f_object);

			// Starting in 8.2, -n and -t are orthogonal, so we now schema qualify
			// the table name in the -t argument and quote both identifiers
			if (((float) $version[1]) >= 8.2) {
				$cmd .= " -t " . $misc->escapeShellArg("\"{$f_schema}\".\"{$f_object}\"");
			} else {
				// If we are 7.4 or higher, assume they are using 7.4 pg_dump and
				// set dump schema as well.  Also, mixed case dumping has been fixed
				// then..
				$cmd .= " -t " . $misc->escapeShellArg($f_object)
					. " -n " . $misc->escapeShellArg($f_schema);
			}
	}

	// Check for GZIP compression specified
	if ($output_method == 'gzipped' && !$dumpall) {
		$cmd .= " -Z 9";
	}

	switch ($_REQUEST['what']) {
		case 'dataonly':
			$cmd .= ' -a';
			if ($_REQUEST['d_format'] == 'sql')
				$cmd .= ' --inserts';
			elseif (isset($_REQUEST['d_oids']))
				$cmd .= ' -o';
			break;
		case 'structureonly':
			$cmd .= ' -s';
			if (isset($_REQUEST['s_clean']))
				$cmd .= ' -c';
			break;
		case 'structureanddata':
			if ($_REQUEST['sd_format'] == 'sql')
				$cmd .= ' --inserts';
			elseif (isset($_REQUEST['sd_oids']))
				$cmd .= ' -o';
			if (isset($_REQUEST['sd_clean']))
				$cmd .= ' -c';
			break;
	}

	if (!$dumpall) {
		putenv('PGDATABASE=' . $_REQUEST['database']);
	}
}


// For show mode, stream output and escape HTML
if ($output_method === 'show') {
	// Stream command output in chunks
	$handle = popen("$cmd 2>&1", 'r');
	if ($handle === false) {
		echo "-- ERROR: Could not execute command\n";
	} else {
		while (!feof($handle)) {
			$chunk = fread($handle, 32768);
			if ($chunk !== false && $chunk !== '') {
				echo htmlspecialchars($chunk);
			}
		}
		pclose($handle);
	}
	echo "</textarea>\n";
	?>
		<div class="export-controls" style="margin-top: 15px;">
			<a href="javascript:history.back()">← Back</a>
			<a href="javascript:location.reload()">↻ Reload</a>
		</div>
		<?php
} else {
	// For downloads, output as-is (binary or plain text)
	passthru($cmd);
}

