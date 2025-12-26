<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\DumpManager;
use PhpPgAdmin\Database\Dump\DumpFactory;
use PhpPgAdmin\Database\Actions\DatabaseActions;
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

	// Set response headers and open output stream
	if ($output_method === 'gzipped') {
		// Use the centralized gzip stream handling
		$output_stream = ExportOutputRenderer::beginGzipStream($filename_base);
		if ($output_stream === null) {
			die('Error: Could not initialize gzipped output stream');
		}

		// Set the dumper to use this stream
		$dumper = DumpFactory::create($subject, $pg);
		$dumper->setOutputStream($output_stream);
	} elseif ($output_method === 'show') {
		ExportOutputRenderer::beginHtmlOutput(null, null);
		$output_stream = null; // Show mode uses echo directly
	} else {
		// 'download' mode - use streaming to php://output for memory efficiency
		$output_stream = ExportOutputRenderer::beginDownloadStream($filename_base, 'application/sql', 'sql');
	}

	// Output dump header
	//echo "-- ", AppContainer::getAppName(), " ", AppContainer::getAppVersion(), " PostgreSQL dump\n";
	//echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

	// Execute dump via internal dumper
	// Note: Dumpers handle their own transaction management (beginDump/endDump)
	$dumper = DumpFactory::create($subject, $pg);
	if ($output_stream !== null) {
		$dumper->setOutputStream($output_stream);
	}

	// If multiple databases are requested, iterate through each
	if ($subject === 'database' && !empty($options['databases'])) {
		foreach ($options['databases'] as $db_name) {
			$params['database'] = $db_name;
			$dumper->dump($subject, $params, $options);

			// For gzipped streams, flush periodically
			if ($output_method === 'gzipped') {
				fflush($output_stream);
			}
		}
	} else {
		// Single database/schema/table/etc
		$dumper->dump($subject, $params, $options);
	}

	// Handle output stream closing
	if ($output_method === 'gzipped' || $output_method === 'download') {
		ExportOutputRenderer::endOutputStream($output_stream);
	} elseif ($output_method === 'show') {
		// Close HTML output for show mode
		ExportOutputRenderer::endHtmlOutput();
	}

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

	$server_info = $misc->getServerInfo();
	setupPgEnvironment($server_info);

	// Build command with connection parameters
	$pg_dumpall = $misc->escapeShellCmd($pg_dumpall_path);
	$cmd = buildDumpCommandWithConnectionParams($pg_dumpall, $server_info);

	// Check version and warn if needed
	if ($output_method === 'show') {
		ExportOutputRenderer::beginHtmlOutput($pg_dumpall_path, '');
		checkAndWarnVersionMismatch($pg_dumpall, 'pg_dumpall', $server_info);
	} else {
		checkAndWarnVersionMismatch($pg_dumpall, 'pg_dumpall', $server_info);
	}

	// Execute based on output method
	if ($output_method === 'gzipped') {
		$output_stream = ExportOutputRenderer::beginGzipStream($filename_base);
		if ($output_stream === null) {
			die('Error: Could not initialize gzipped output stream');
		}
		execute_dump_command_streaming($cmd, $output_stream);
		ExportOutputRenderer::endOutputStream($output_stream);
	} elseif ($output_method === 'download') {
		$output_stream = ExportOutputRenderer::beginDownloadStream($filename_base, 'application/sql', 'sql');
		execute_dump_command_streaming($cmd, $output_stream);
		ExportOutputRenderer::endOutputStream($output_stream);
	} elseif ($output_method === 'show') {
		execute_dump_command($cmd, 'show');
		ExportOutputRenderer::endHtmlOutput();
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
	setupPgEnvironment($server_info);

	// Build base command with connection parameters
	$base_cmd = buildDumpCommandWithConnectionParams($exe, $server_info);

	// Check version and warn if needed
	$pg_version = checkAndWarnVersionMismatch($exe, 'pg_dump', $server_info);

	// Build per-database pg_dump commands
	$db_commands = [];
	$db_names = [];
	foreach ($selected_databases as $db_name) {
		$pg->fieldClean($db_name);
		$db_cmd = $base_cmd . ' ' . $misc->escapeShellArg($db_name);
		$db_cmd = addPgDumpFormatOptions($db_cmd, $_REQUEST['what'] ?? 'structureanddata', $insert_format, isset($_REQUEST['drop_objects']));
		$db_commands[] = $db_cmd;
		$db_names[] = $db_name;
	}
	$cmd = $db_commands;

	// Set headers and execute
	if ($output_method === 'gzipped') {
		$output_stream = ExportOutputRenderer::beginGzipStream($filename_base);
		if ($output_stream === null) {
			die('Error: Could not initialize gzipped output stream');
		}
		foreach ($cmd as $idx => $db_cmd) {
			$dbName = $db_names[$idx] ?? null;
			if ($dbName !== null) {
				$header = "--\n-- Dumping database: \"" . addslashes($dbName) . "\"\n--\n\\connect \"" . addslashes($dbName) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
				fwrite($output_stream, $header);
			}
			execute_dump_command_streaming($db_cmd, $output_stream);
			// Reset replication role after this database stream
			fwrite($output_stream, "SET session_replication_role = 'origin';\n\n");
			fflush($output_stream);
		}
		ExportOutputRenderer::endOutputStream($output_stream);
	} elseif ($output_method === 'download') {
		$output_stream = ExportOutputRenderer::beginDownloadStream($filename_base, 'application/sql', 'sql');
		foreach ($cmd as $idx => $db_cmd) {
			$dbName = $db_names[$idx] ?? null;
			if ($dbName !== null) {
				$header = "--\n-- Dumping database: \"" . addslashes($dbName) . "\"\n--\n\\connect \"" . addslashes($dbName) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
				fwrite($output_stream, $header);
			}
			execute_dump_command_streaming($db_cmd, $output_stream);
			// Reset replication role after this database stream
			fwrite($output_stream, "SET session_replication_role = 'origin';\n\n");
			fflush($output_stream);
		}
		ExportOutputRenderer::endOutputStream($output_stream);
	} elseif ($output_method === 'show') {
		ExportOutputRenderer::beginHtmlOutput($exe_path_pgdump, floatval($version[1] ?? ''));
		foreach ($cmd as $idx => $db_cmd) {
			$dbName = $db_names[$idx] ?? null;
			if ($dbName !== null) {
				$header = "--\n-- Dumping database: \"" . addslashes($dbName) . "\"\n--\n\\connect \"" . addslashes($dbName) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
				echo htmlspecialchars($header);
			}
			execute_dump_command($db_cmd, 'show');
			if ($dbName !== null) {
				echo htmlspecialchars("SET session_replication_role = 'origin';\n\n");
			}
		}
		ExportOutputRenderer::endHtmlOutput();
	} else {
		foreach ($cmd as $db_cmd) {
			execute_dump_command($db_cmd, $output_method);
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

	// Get server connection info
	$server_info = $misc->getServerInfo();

	// Setup environment and build command
	setupPgEnvironment($server_info);
	$base_cmd = buildDumpCommandWithConnectionParams($exe, $server_info);

	// Check version and warn if needed
	checkAndWarnVersionMismatch($exe, 'pg_dump', $server_info);

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
	$cmd = addPgDumpFormatOptions($cmd, $_REQUEST['what'] ?? 'structureanddata', $insert_format, isset($_REQUEST['drop_objects']));

	// Set database for single database export
	if (isset($_REQUEST['database'])) {
		putenv('PGDATABASE=' . $_REQUEST['database']);
	}

	// Set headers for gzipped/download/show and execute
	if ($output_method === 'gzipped') {
		$output_stream = ExportOutputRenderer::beginGzipStream($filename_base);
		if ($output_stream === null) {
			die('Error: Could not initialize gzipped output stream');
		}
		// If we know the target database (from request), emit header + connect
		$targetDb = $_REQUEST['database'] ?? null;
		if ($targetDb) {
			$header = "--\n-- Dumping database: \"" . addslashes($targetDb) . "\"\n--\n\\connect \"" . addslashes($targetDb) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
			fwrite($output_stream, $header);
		}
		execute_dump_command_streaming($cmd, $output_stream);
		// Reset replication role after this database stream
		fwrite($output_stream, "SET session_replication_role = 'origin';\n\n");
		fflush($output_stream);
		ExportOutputRenderer::endOutputStream($output_stream);
	} elseif ($output_method === 'download') {
		$output_stream = ExportOutputRenderer::beginDownloadStream($filename_base, 'application/sql', 'sql');
		$targetDb = $_REQUEST['database'] ?? null;
		if ($targetDb) {
			$header = "--\n-- Dumping database: \"" . addslashes($targetDb) . "\"\n--\n\\connect \"" . addslashes($targetDb) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
			fwrite($output_stream, $header);
		}
		execute_dump_command_streaming($cmd, $output_stream);
		// Reset replication role after this database stream
		fwrite($output_stream, "SET session_replication_role = 'origin';\n\n");
		fflush($output_stream);
		ExportOutputRenderer::endOutputStream($output_stream);
	} elseif ($output_method === 'show') {
		ExportOutputRenderer::beginHtmlOutput($exe_path, $version[1]);
		$targetDb = $_REQUEST['database'] ?? null;
		if ($targetDb) {
			$header = "--\n-- Dumping database: \"" . addslashes($targetDb) . "\"\n--\n\\connect \"" . addslashes($targetDb) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
			echo htmlspecialchars($header);
		}
		execute_dump_command($cmd, 'show');
		if ($targetDb) {
			echo htmlspecialchars("SET session_replication_role = 'origin';\n\n");
		}
		ExportOutputRenderer::endHtmlOutput();
	} else {
		execute_dump_command($cmd, $output_method);
	}
	exit;
}

// Helper function to stream pg_dump output directly to a gzipped stream
// This pipes pg_dump → PHP → gzip filter → browser with no buffering
// Based on colleague's guidance for true streaming without memory overhead
function execute_dump_command_streaming($command, $output_stream)
{
	$descriptors = [
		0 => ['pipe', 'r'],  // stdin (not used)
		1 => ['pipe', 'w'],  // stdout (pg_dump output)
		2 => ['pipe', 'w'],  // stderr (errors, ignored)
	];
	$process = proc_open($command, $descriptors, $pipes);
	if (!is_resource($process)) {
		fwrite(STDERR, "ERROR: Could not execute pg_dump command\n");
		return;
	}

	fclose($pipes[0]); // Close stdin

	// Stream pg_dump output directly to the gzipped stream
	// Read in 8KB chunks: small enough to not overwhelm memory, large enough for efficiency
	while (!feof($pipes[1])) {
		$chunk = fread($pipes[1], 8192);
		if ($chunk === false || $chunk === '') {
			break;
		}
		// Write directly to gzipped stream (zlib.deflate filter compresses on-the-fly)
		fwrite($output_stream, $chunk);
	}

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);
}

// Helper function to execute a single command for non-gzipped output
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
		// For downloads (non-gzipped), use proc_open for better control
		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w'],  // stderr
		];
		$process = proc_open($command, $descriptors, $pipes);
		if (is_resource($process)) {
			fclose($pipes[0]); // Close stdin
			while (!feof($pipes[1])) {
				$chunk = fread($pipes[1], 32768);
				if ($chunk !== false && $chunk !== '') {
					echo $chunk;
				}
			}
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);
		} else {
			echo "-- ERROR: Could not execute command\n";
		}
	}
}

/**
 * Set up PostgreSQL environment variables for connection.
 * Called by both pg_dump and pg_dumpall.
 */
function setupPgEnvironment($server_info)
{
	putenv('PGPASSWORD=' . $server_info['password']);
	putenv('PGUSER=' . $server_info['username']);
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		putenv('PGHOST=' . $server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		putenv('PGPORT=' . $server_info['port']);
	}
}

/**
 * Build connection parameters for pg_dump/pg_dumpall command.
 * Returns the base command with host, port, and user flags.
 */
function buildDumpCommandWithConnectionParams($exe_path, $server_info)
{
	$cmd = $exe_path;
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		$cmd .= ' -h ' . AppContainer::getMisc()->escapeShellArg($server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		$cmd .= ' -p ' . intval($server_info['port']);
	}
	if ($server_info['username'] !== null && $server_info['username'] !== '') {
		$cmd .= ' -U ' . AppContainer::getMisc()->escapeShellArg($server_info['username']);
	}
	return $cmd;
}

/**
 * Add format and structure options to pg_dump command.
 * Appends flags for data-only, structure-only, INSERT format, and DROP IF EXISTS.
 */
function addPgDumpFormatOptions($cmd, $what, $insert_format, $drop_objects = false)
{
	switch ($what) {
		case 'dataonly':
			$cmd .= ' -a';
			if ($insert_format !== 'copy') {
				$cmd .= ' --inserts';
			}
			break;
		case 'structureonly':
			$cmd .= ' -s';
			if ($drop_objects) {
				$cmd .= ' -c';
			}
			break;
		case 'structureanddata':
			if ($insert_format !== 'copy') {
				$cmd .= ' --inserts';
			}
			if ($drop_objects) {
				$cmd .= ' -c';
			}
			break;
	}
	return $cmd;
}

/**
 * Check pg_dump/pg_dumpall version and warn if older than server version.
 * Outputs SQL comment warning if version mismatch detected.
 * Warning appears in the actual dump (both in browser and downloaded file).
 */
function checkAndWarnVersionMismatch($exe_path, $exe_name, $server_info)
{
	$version_output = shell_exec($exe_path . ' --version 2>&1');
	if (!$version_output) {
		return null;
	}

	$version = [];
	preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", trim($version_output), $version);

	if (empty($version)) {
		return null;
	}

	$dump_version = floatval($version[1]);
	$server_version = floatval($server_info['pgVersion'] ?? 0);

	if ($server_version > 0 && $dump_version < $server_version) {
		echo "-- WARNING: $exe_name version ($dump_version) is older than PostgreSQL server version ($server_version)\n";
		echo "-- Some advanced features may be limited or the dump may be incomplete. Consider using the internal dumper.\n\n";
	}

	return $version[1] ?? '';
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

