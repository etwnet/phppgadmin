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

// Determine whether to use internal dumper or pg_dump
$use_internal = isset($_REQUEST['use_internal']) || empty(DumpManager::getDumpExecutable($dumpall));
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
	$options = [
		'format' => $_REQUEST['d_format'] ?? 'sql',
		'clean' => isset($_REQUEST['s_clean']) || isset($_REQUEST['sd_clean']),
		'if_not_exists' => isset($_REQUEST['if_not_exists']),
		'structure_only' => ($_REQUEST['what'] === 'structureonly'),
		'data_only' => ($_REQUEST['what'] === 'dataonly')
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

	// Output dump header
	echo "-- phpPgAdmin PostgreSQL dump\n";
	echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

	// Execute dump via internal dumper
	// Note: Dumpers handle their own transaction management (beginDump/endDump)
	$dumper = DumpFactory::create($subject, $pg);
	$dumper->dump($subject, $params, $options);

	// Close textarea for show mode
	if ($output_method === 'show') {
		echo "</textarea>\n";
		?>
								<div class="export-controls" style="margin-top: 15px;">
									<a href="javascript:history.back()">← Back</a>
									<a href="javascript:location.reload()">↻ Reload</a>
								</div>
								<?php
	}

	exit;
}

// ============================================================================
// EXTERNAL PG_DUMP/PG_DUMPALL PATH (fallback only)
// ============================================================================

// Get the executable path (already determined to exist above)
$exe_path = DumpManager::getDumpExecutable($dumpall);
$exe = $misc->escapeShellCmd($exe_path);

// Obtain the pg_dump version number
$version = [];
preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", exec($exe . " --version"), $version);

if (empty($version)) {
	echo "Error: Could not execute " . htmlspecialchars($exe_path) . "\n";
	echo "The executable exists but could not be run. Please check permissions.\n";
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

// Build pg_dump command
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

// Schema/table handling (for non-cluster dumps)
$f_schema = '';
$f_object = '';

if (!$dumpall && isset($_REQUEST['schema'])) {
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
		$f_object = $_REQUEST[$_REQUEST['subject']];
		$pg->fieldClean($f_object);

		// Starting in 8.2, -n and -t are orthogonal, so we now schema qualify
		// the table name in the -t argument and quote both identifiers
		if (((float) $version[1]) >= 8.2) {
			$cmd .= " -t " . $misc->escapeShellArg("\"{$f_schema}\".\"{$f_object}\"");
		} else {
			// For versions < 8.2, schema and table are separate arguments
			$cmd .= " -t " . $misc->escapeShellArg($f_object)
				. " -n " . $misc->escapeShellArg($f_schema);
		}
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

// Add GZIP compression if requested
if ($output_method === 'gzipped' && !$dumpall) {
	$cmd .= " -Z 9";
	header('Content-Type: application/download');
	header('Content-Disposition: attachment; filename=' . $filename_base . '.sql.gz');
} else {
	// Set response headers for non-gzipped output
	if ($output_method === 'download') {
		header('Content-Type: application/download');
		header('Content-Disposition: attachment; filename=' . $filename_base . '.sql');
	} else {
		header('Content-Type: text/html; charset=utf-8');
	}
}

// Set database for non-cluster dumps
if (!$dumpall && isset($_REQUEST['database'])) {
	putenv('PGDATABASE=' . $_REQUEST['database']);
}

// For show mode, stream output and escape HTML
if ($output_method === 'show') {
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
				<?php
				echo "<textarea readonly>";
				echo "-- Dumping with " . htmlspecialchars($exe_path) . " version " . $version[1] . "\n\n";

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

