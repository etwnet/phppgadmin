<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\DumpManager;
use PhpPgAdmin\Database\Dump\DumpFactory;

/**
 * Does an export of a database, schema, table, or view (via internal PHP dumper or pg_dump fallback).
 * Uses DumpFactory internally; pg_dump is used only if explicitly requested via $_REQUEST['use_pgdump'].
 * Streams output to screen or downloads file.
 *
 * $Id: dbexport.php,v 1.22 2007/03/25 03:15:09 xzilla Exp $
 */

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

include_once('./libraries/bootstrap.php');

// Include application functions
AppContainer::setSkipHtmlFrame(true);

$pg = AppContainer::getPostgres();
$misc = AppContainer::getMisc();

// Are we doing a cluster-wide dump or just a per-database dump
$dumpall = $_REQUEST['subject'] == 'server';
$output_method = $_REQUEST['output'] ?? 'show';

// Determine whether to use internal dumper or pg_dump
// Default: use internal dumper (preferred for cluster exports with full object support)
// Only use pg_dump if explicitly requested via checkbox
$use_internal = !isset($_REQUEST['use_pgdump']) || empty(DumpManager::getDumpExecutable($dumpall));
$filename_base = generateDumpFilename($_REQUEST['subject'], $_REQUEST);

// ============================================================================
// INTERNAL PHP DUMPER PATH
// ============================================================================
if ($use_internal) {
	$subject = $_REQUEST['subject'] ?? 'database';
	$params = [
		'table' => $_REQUEST['table'] ?? null,
		'view' => $_REQUEST['view'] ?? null,
		'schema' => $_REQUEST['schema'] ?? null,
		'database' => $_REQUEST['database'] ?? null
	];
	// Determine the actual export format based on insert_format
	// For server dumps with data, use insert_format to decide COPY vs SQL
	$insert_format = $_REQUEST['insert_format'] ?? 'copy';
	$export_format = ($insert_format === 'copy') ? 'copy' : 'sql';

	$options = [
		'format' => $export_format,
		'drop_objects' => isset($_REQUEST['drop_objects']),
		'if_not_exists' => isset($_REQUEST['if_not_exists']),
		'include_comments' => isset($_REQUEST['include_comments']),
		'export_roles' => isset($_REQUEST['export_roles']),
		'export_tablespaces' => isset($_REQUEST['export_tablespaces']),
		'structure_only' => ($_REQUEST['what'] === 'structureonly'),
		'data_only' => ($_REQUEST['what'] === 'dataonly'),
		'databases' => isset($_REQUEST['databases']) ? (array) $_REQUEST['databases'] : [],
		'insert_format' => $insert_format,
		'truncate_tables' => isset($_REQUEST['truncate_tables'])
	];

	// Set response headers
	if ($output_method === 'download') {
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql');
	} else {
		header('Content-Type: text/html; charset=utf-8');
	}

	// Display UI for show mode
	if ($output_method === 'show') {
		beginHtmlOutput(null, null);
	}

	// Output dump header
	//echo "-- ", AppContainer::getAppName(), " ", AppContainer::getAppVersion(), " PostgreSQL dump\n";
	//echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

	// Execute dump via internal dumper
	// Note: Dumpers handle their own transaction management (beginDump/endDump)
	$dumper = DumpFactory::create($subject, $pg);
	$dumper->dump($subject, $params, $options);

	// Close textarea for show mode
	if ($output_method === 'show') {
		finishHtmlOutput();
	}

	exit;
}

// ============================================================================
// EXTERNAL PG_DUMP/PG_DUMPALL PATH (fallback only)
// ============================================================================

// Handle database selection for server exports
$selected_databases = [];
$use_pg_dumpall = $dumpall;

// Clear cached dump executable detection to force re-check
unset($_SESSION['dump_executable_pg_dump']);
unset($_SESSION['dump_executable_pg_dumpall']);

if ($dumpall && !empty($_REQUEST['databases'])) {
	// If databases were selected in a server export, use pg_dump for each
	// instead of pg_dumpall (which cannot be filtered)
	$selected_databases = (array) $_REQUEST['databases'];
	$use_pg_dumpall = false;  // Switch to per-database dumps
}

// Get the appropriate executable path
$exe_path = DumpManager::getDumpExecutable($use_pg_dumpall);
if (!$exe_path) {
	echo "Error: Could not find pg_dump or pg_dumpall executable.\n";
	exit;
}

$exe = $misc->escapeShellCmd($exe_path);

// Obtain the pg_dump version number
$version = [];
// Use shell_exec for better error capture on Windows
$version_output = shell_exec("$exe --version 2>&1");
if (!$version_output) {
	echo "Error: Could not execute " . htmlspecialchars($exe_path) . "\n";
	echo "The executable exists but could not be run. Please check permissions.\n";
	exit;
}

preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", trim($version_output), $version);

if (empty($version)) {
	echo "Error: Could not determine pg_dump version.\n";
	echo "Output: " . htmlspecialchars($version_output) . "\n";
	exit;
}

// Get server connection info for version checking
$server_info = $misc->getServerInfo();

// Check for version mismatch and warn user if applicable
$version_mismatch = false;
if (!empty($server_info['pgVersion'])) {
	$dump_version = floatval($version[1]);
	$server_version = floatval($server_info['pgVersion']);
	if ($dump_version < $server_version) {
		$version_mismatch = true;
		echo "<!-- WARNING: pg_dump version ($dump_version) is older than PostgreSQL server version ($server_version) -->\n";
		echo "<!-- Some advanced features may be limited. Consider using the internal dumper. -->\n\n";
	}
}

// Set environmental variables for pg_dump connection
putenv('PGPASSWORD=' . $server_info['password']);
putenv('PGUSER=' . $server_info['username']);

if ($server_info['host'] !== null && $server_info['host'] !== '') {
	putenv('PGHOST=' . $server_info['host']);
}

if ($server_info['port'] !== null && $server_info['port'] !== '') {
	putenv('PGPORT=' . $server_info['port']);
}

// Build base pg_dump/pg_dumpall command with connection parameters
$base_cmd = $exe;

// Add explicit host if specified (prevents defaulting to Unix socket)
if ($server_info['host'] !== null && $server_info['host'] !== '') {
	$base_cmd .= ' -h ' . $misc->escapeShellArg($server_info['host']);
}

// Add explicit port if specified (non-default)
if ($server_info['port'] !== null && $server_info['port'] !== '') {
	$base_cmd .= ' -p ' . intval($server_info['port']);
}

// Add explicit username
if ($server_info['username'] !== null && $server_info['username'] !== '') {
	$base_cmd .= ' -U ' . $misc->escapeShellArg($server_info['username']);
}

// Build per-database commands if using filtered databases, otherwise single command
if (!$use_pg_dumpall && !empty($selected_databases)) {
	// Build separate pg_dump commands for each selected database
	$db_commands = [];
	foreach ($selected_databases as $db_name) {
		$pg->fieldClean($db_name);
		$db_cmd = $base_cmd . ' ' . $misc->escapeShellArg($db_name);

		// Add format options for each database dump
		switch ($_REQUEST['what'] ?? 'structureanddata') {
			case 'dataonly':
				$db_cmd .= ' -a';
				if (($_REQUEST['d_format'] ?? 'sql') === 'sql') {
					$db_cmd .= ' --inserts';
				}
				break;
			case 'structureonly':
				$db_cmd .= ' -s';
				if (isset($_REQUEST['s_clean'])) {
					$db_cmd .= ' -c';
				}
				break;
			case 'structureanddata':
				if (($_REQUEST['sd_format'] ?? 'sql') === 'sql') {
					$db_cmd .= ' --inserts';
				}
				if (isset($_REQUEST['sd_clean'])) {
					$db_cmd .= ' -c';
				}
				break;
		}

		$db_commands[] = $db_cmd;
	}
	$cmd = $db_commands;  // Array of commands
} else {
	// Single command mode (pg_dumpall or single database)
	$cmd = $base_cmd;

	// Schema/table handling (for non-cluster, non-filtered dumps)
	$f_schema = '';
	$f_object = '';

	if (!$use_pg_dumpall && isset($_REQUEST['schema'])) {
		$f_schema = $_REQUEST['schema'];
		$pg->fieldClean($f_schema);
	}

	// Check for a specified table/view
	switch ($_REQUEST['subject']) {
		case 'schema':
			// Schema export
			$cmd .= " -n " . $misc->escapeShellArg("\"{$f_schema}\"");
			break;
		case 'table':
		case 'view':
			// Table or view export
			$f_object = $_REQUEST[$_REQUEST['subject']];
			$pg->fieldClean($f_object);
			$cmd .= " -t " . $misc->escapeShellArg("\"{$f_schema}\".\"{$f_object}\"");
			break;
	}

	// Add format/compression options based on request
	switch ($_REQUEST['what'] ?? 'structureanddata') {
		case 'dataonly':
			$cmd .= ' -a';
			if (($_REQUEST['d_format'] ?? 'sql') === 'sql') {
				$cmd .= ' --inserts';
			}
			break;
		case 'structureonly':
			$cmd .= ' -s';
			if (isset($_REQUEST['s_clean'])) {
				$cmd .= ' -c';
			}
			break;
		case 'structureanddata':
			if (($_REQUEST['sd_format'] ?? 'sql') === 'sql') {
				$cmd .= ' --inserts';
			}
			if (isset($_REQUEST['sd_clean'])) {
				$cmd .= ' -c';
			}
			break;
	}

	// Set database for non-cluster, non-filtered dumps
	if (!$use_pg_dumpall && isset($_REQUEST['database'])) {
		putenv('PGDATABASE=' . $_REQUEST['database']);
	}
}

// For show mode, stream output and escape HTML
if ($output_method === 'show') {
	beginHtmlOutput($exe_path, $version[1]);

	// Execute command(s)
	if (is_array($cmd)) {
		// Multiple database dumps
		foreach ($cmd as $idx => $db_cmd) {
			echo "-- Database " . ($idx + 1) . " of " . count($cmd) . "\n";
			execute_dump_command($db_cmd, 'show');
		}
	} else {
		// Single command
		execute_dump_command($cmd, 'show');
	}

	finishHtmlOutput();
} else {
	// For downloads, execute command(s)
	if (is_array($cmd)) {
		// Multiple database dumps
		foreach ($cmd as $db_cmd) {
			execute_dump_command($db_cmd, 'download');
		}
	} else {
		// Single command
		execute_dump_command($cmd, 'download');
	}
}

// Helper function to execute a single command
function execute_dump_command($command, $output_method)
{
	if ($output_method === 'show') {
		// Stream command output in chunks
		$handle = popen("$command 2>&1", 'r');
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
	} else {
		// For downloads, output as-is (binary or plain text)
		passthru($command);
	}
}

/**
 * Generate a descriptive filename for the dump.
 * Shared logic used by both dataexport.php and dbexport.php.
 */
function generateDumpFilename($subject, $request)
{
	$timestamp = date('Ymd_His');
	$filename_base = 'dump_' . $timestamp;
	$filename_parts = [];

	// Add database name if available
	if (isset($request['database']) && $subject !== 'server') {
		$filename_parts[] = $request['database'];
	}

	// Add schema name if available
	if (isset($request['schema'])) {
		$filename_parts[] = $request['schema'];
	}

	// Add table or view name if available
	if (isset($request['table'])) {
		$filename_parts[] = $request['table'];
	} elseif (isset($request['view'])) {
		$filename_parts[] = $request['view'];
	}

	// Add export type shorthand if available
	if (isset($request['what'])) {
		$what_map = [
			'dataonly' => 'data',
			'structureonly' => 'struct',
			'structureanddata' => 'full'
		];
		$what_short = $what_map[$request['what']] ?? 'export';
		$filename_parts[] = $what_short;
	}

	// Build final filename
	if (!empty($filename_parts)) {
		$filename_base .= '_' . implode('_', array_map(
			function ($v) {
				return preg_replace('/[^a-zA-Z0-9_-]/', '', $v);
			},
			$filename_parts
		));
	}

	return $filename_base;
}

/**
 * Output opening HTML structure for show mode export (styles, controls, textarea).
 */
function beginHtmlOutput($exe_path, $version)
{
	// Include application functions
	AppContainer::setSkipHtmlFrame(false);
	$misc = AppContainer::getMisc();
	$misc->printHeader("Database Export", null);
	$misc->printBody();
	$misc->printTrail('server');
	$misc->printTabs('server', 'export');
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
	</style>
	<div class="export-controls">
		<a href="javascript:history.back()">← Back</a>
		<a href="javascript:location.reload()">↻ Reload</a>
	</div>
	<?php
	echo "<textarea class=\"dbexport\" readonly>";
	if ($exe_path && $version) {
		echo "-- Dumping with " . htmlspecialchars($exe_path) . " version " . $version . "\n\n";
	}
}

/**
 * Output closing HTML structure for show mode export (closing textarea and controls).
 */
function finishHtmlOutput()
{
	echo "</textarea>\n";
	?>
	<div class="export-controls" style="margin-top: 15px;">
		<a href="javascript:history.back()">← Back</a>
		<a href="javascript:location.reload()">↻ Reload</a>
	</div>
	<?php
	$misc = AppContainer::getMisc();
	$misc->printFooter();
}
