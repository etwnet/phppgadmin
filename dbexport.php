<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\DumpManager;
use PhpPgAdmin\Database\Dump\DumpFactory;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Database\Export\FormatterFactory;
use PhpPgAdmin\Gui\ExportOutputRenderer;
/**
 * Does an export of a database, schema, table, or view (via internal PHP dumper or pg_dump fallback).
 * Uses DumpFactory internally; pg_dump is used only if explicitly requested via $_REQUEST['dumper'].
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

// Parameter handling
// DumpRenderer uses output_format (sql/csv/tab/html/xml/json) and insert_format (copy/multi/single for SQL only)
$output_format = $_REQUEST['output_format'] ?? 'sql';
$insert_format = $_REQUEST['insert_format'] ?? 'copy';

// Are we doing a cluster-wide dump or just a per-database dump
//$dumpall = $_REQUEST['subject'] == 'server';
$output_method = $_REQUEST['output'] ?? 'download';

// Determine dumper selection
$dumper = $_REQUEST['dumper'] ?? 'internal';
$use_pg_dumpall = ($dumper === 'pg_dumpall');
$use_pgdump = ($dumper === 'pgdump');
$use_internal = ($dumper === 'internal');
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
	// Determine the actual export format based on output_format and insert_format
	// output_format = sql/csv/tab/html/xml/json
	// insert_format = copy/multi/single (only for SQL format)
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
		ExportOutputRenderer::setOutputHeaders('download', $filename_base, 'application/sql', 'sql');
	} elseif ($output_method === 'gzipped') {
		ExportOutputRenderer::setOutputHeaders('gzipped', $filename_base, 'application/gzip', 'sql.gz');
	} else {
		ExportOutputRenderer::beginHtmlOutput(null, null);
	}

	// Output dump header
	//echo "-- ", AppContainer::getAppName(), " ", AppContainer::getAppVersion(), " PostgreSQL dump\n";
	//echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

	// Execute dump via internal dumper
	// Note: Dumpers handle their own transaction management (beginDump/endDump)
	$dumper = DumpFactory::create($subject, $pg);
	$dumper->dump($subject, $params, $options);

	// Close HTML output for show mode
	if ($output_method === 'show') {
		ExportOutputRenderer::finishHtmlOutput();
	}
	// For gzipped output, ob_gzhandler auto-flushes on script end—no manual flush needed

	exit;
}


// ============================================================================
// EXTERNAL PG_DUMP/PG_DUMPALL PATH (fallback only)
// ============================================================================

// Handle database selection for server exports
$selected_databases = [];

// Clear cached dump executable detection to force re-check
unset($_SESSION['dump_executable_pg_dump']);
unset($_SESSION['dump_executable_pg_dumpall']);

// ============================================================================
// HANDLE PG_DUMPALL (full cluster mode)
// ============================================================================
if ($use_pg_dumpall) {
	// pg_dumpall: always full cluster, no options
	$pg_dumpall_path = DumpManager::getDumpExecutable(true);
	if (!$pg_dumpall_path) {
		echo "Error: Could not find pg_dumpall executable.\n";
		exit;
	}
	$pg_dumpall = $misc->escapeShellCmd($pg_dumpall_path);
	$server_info = $misc->getServerInfo();
	putenv('PGPASSWORD=' . $server_info['password']);
	putenv('PGUSER=' . $server_info['username']);
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		putenv('PGHOST=' . $server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		putenv('PGPORT=' . $server_info['port']);
	}
	$cmd = $pg_dumpall;
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		$cmd .= ' -h ' . $misc->escapeShellArg($server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		$cmd .= ' -p ' . intval($server_info['port']);
	}
	if ($server_info['username'] !== null && $server_info['username'] !== '') {
		$cmd .= ' -U ' . $misc->escapeShellArg($server_info['username']);
	}

	// Set headers for gzipped/download/show and execute
	if ($output_method === 'gzipped') {
		header('Content-Type: application/gzip');
		header('Content-Encoding: gzip');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql.gz');
		ob_start('ob_gzhandler');
	} elseif ($output_method === 'download') {
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql');
	} else {
		header('Content-Type: text/html; charset=utf-8');
	}
	if ($output_method === 'show') {
		ExportOutputRenderer::beginHtmlOutput($pg_dumpall_path, '');
		execute_dump_command($cmd, 'show');
		ExportOutputRenderer::finishHtmlOutput();
	} else {
		execute_dump_command($cmd, $output_method);
		if ($output_method === 'gzipped') {
			ob_end_flush();
		}
	}
	exit;
}

// If not pg_dumpall, handle pg_dump logic
// Smart: if pg_dump selected AND all databases selected AND roles/tablespaces AND structure+data
// then use pg_dumpall instead for efficiency
if ($use_pgdump) {
	$selected_dbs = isset($_REQUEST['databases']) ? (array) $_REQUEST['databases'] : [];
	$what = $_REQUEST['what'] ?? 'structureanddata';
	$export_roles = isset($_REQUEST['export_roles']);
	$export_tablespaces = isset($_REQUEST['export_tablespaces']);

	// Check if all non-template databases are selected
	$databaseActions = new DatabaseActions($pg);
	$all_dbs = $databaseActions->getDatabases(null, true);
	$all_dbs->moveFirst();
	$all_db_list = [];
	while ($all_dbs && !$all_dbs->EOF) {
		$dname = $all_dbs->fields['datname'];
		if (strpos($dname, 'template') !== 0) {
			$all_db_list[] = $dname;
		}
		$all_dbs->moveNext();
	}
	sort($selected_dbs);
	sort($all_db_list);
	$allDbsSelected = ($selected_dbs === $all_db_list && !empty($selected_dbs));

	// Smart logic: use pg_dumpall if all DBs + roles + tablespaces + structure+data
	if ($allDbsSelected && $export_roles && $export_tablespaces && $what === 'structureanddata') {
		// Switch to pg_dumpall for efficiency
		$pg_dumpall_path = DumpManager::getDumpExecutable(true);
		if ($pg_dumpall_path) {
			$use_pgdump = false;
			$use_pg_dumpall = true;
			// Re-run the pg_dumpall block
			goto pg_dumpall_export;
		}
	}

	// Otherwise, continue with pg_dump for filtered databases
	$selected_databases = $selected_dbs;
}

// If databases were selected in a server export, use pg_dump for each
// instead of pg_dumpall (which cannot be filtered)
if ($use_pgdump && !empty($selected_databases)) {
	$exe_path_pgdump = DumpManager::getDumpExecutable(false);
	if (!$exe_path_pgdump) {
		echo "Error: Could not find pg_dump executable.\n";
		exit;
	}

	$exe = $misc->escapeShellCmd($exe_path_pgdump);
	$server_info = $misc->getServerInfo();
	putenv('PGPASSWORD=' . $server_info['password']);
	putenv('PGUSER=' . $server_info['username']);
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		putenv('PGHOST=' . $server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		putenv('PGPORT=' . $server_info['port']);
	}

	$base_cmd = $exe;
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		$base_cmd .= ' -h ' . $misc->escapeShellArg($server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		$base_cmd .= ' -p ' . intval($server_info['port']);
	}
	if ($server_info['username'] !== null && $server_info['username'] !== '') {
		$base_cmd .= ' -U ' . $misc->escapeShellArg($server_info['username']);
	}

	// Build per-database pg_dump commands
	$db_commands = [];
	foreach ($selected_databases as $db_name) {
		$pg->fieldClean($db_name);
		$db_cmd = $base_cmd . ' ' . $misc->escapeShellArg($db_name);
		switch ($_REQUEST['what'] ?? 'structureanddata') {
			case 'dataonly':
				$db_cmd .= ' -a';
				if ($insert_format !== 'copy') {
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
				if ($insert_format !== 'copy') {
					$db_cmd .= ' --inserts';
				}
				if (isset($_REQUEST['sd_clean'])) {
					$db_cmd .= ' -c';
				}
				break;
		}
		$db_commands[] = $db_cmd;
	}
	$cmd = $db_commands;

	// Set headers and execute
	if ($output_method === 'gzipped') {
		header('Content-Type: application/gzip');
		header('Content-Encoding: gzip');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql.gz');
		ob_start('ob_gzhandler');
	} elseif ($output_method === 'download') {
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql');
	} else {
		ExportOutputRenderer::beginHtmlOutput($exe_path_pgdump, floatval($version[1] ?? ''));
	}

	if ($output_method === 'show') {
		foreach ($cmd as $db_cmd) {
			execute_dump_command($db_cmd, 'show');
		}
		ExportOutputRenderer::finishHtmlOutput();
	} else {
		foreach ($cmd as $db_cmd) {
			execute_dump_command($db_cmd, $output_method);
		}
		if ($output_method === 'gzipped') {
			ob_end_flush();
		}
	}
	exit;
}

pg_dumpall_export:

// Fallback: handle single database or table/view export with pg_dump
if ($use_pgdump) {
	$exe_path = DumpManager::getDumpExecutable(false);
	if (!$exe_path) {
		echo "Error: Could not find pg_dump executable.\n";
		exit;
	}

	$exe = $misc->escapeShellCmd($exe_path);

	// Obtain the pg_dump version number
	$version = [];
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

	// Build base pg_dump command with connection parameters
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

	// Single command mode for single database/table/schema
	$cmd = $base_cmd;

	// Schema/table handling
	$f_schema = '';
	$f_object = '';

	if (isset($_REQUEST['schema'])) {
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

	// Add format options based on request
	switch ($_REQUEST['what'] ?? 'structureanddata') {
		case 'dataonly':
			$cmd .= ' -a';
			if ($insert_format !== 'copy') {
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
			if ($insert_format !== 'copy') {
				$cmd .= ' --inserts';
			}
			if (isset($_REQUEST['sd_clean'])) {
				$cmd .= ' -c';
			}
			break;
	}

	// Set database for single database export
	if (isset($_REQUEST['database'])) {
		putenv('PGDATABASE=' . $_REQUEST['database']);
	}

	// Set headers for gzipped/download/show and execute
	if ($output_method === 'gzipped') {
		header('Content-Type: application/gzip');
		header('Content-Encoding: gzip');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql.gz');
		ob_start('ob_gzhandler');
	} elseif ($output_method === 'download') {
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql');
	} else {
		ExportOutputRenderer::beginHtmlOutput($exe_path, $version[1]);
	}

	if ($output_method === 'show') {
		execute_dump_command($cmd, 'show');
		ExportOutputRenderer::finishHtmlOutput();
	} else {
		execute_dump_command($cmd, $output_method);
		if ($output_method === 'gzipped') {
			ob_end_flush();
		}
	}
	exit;
}

// Set headers for gzipped downloads
if ($output_method === 'gzipped') {
	header('Content-Type: application/gzip');
	header('Content-Encoding: gzip');
	header('Content-Disposition: attachment; filename=' . $filename_base . '.sql.gz');
	ob_start('ob_gzhandler');
}

// For show mode, stream output and escape HTML
if ($output_method === 'show') {
	ExportOutputRenderer::beginHtmlOutput($exe_path, $version[1]);

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

	ExportOutputRenderer::finishHtmlOutput();
} else {
	// For downloads (plain and gzipped), execute command(s)
	if (is_array($cmd)) {
		// Multiple database dumps
		foreach ($cmd as $db_cmd) {
			execute_dump_command($db_cmd, $output_method);
		}
	} else {
		// Single command
		execute_dump_command($cmd, $output_method);
	}

	// Finalize gzip compression if active
	if ($output_method === 'gzipped') {
		ob_end_flush();
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

