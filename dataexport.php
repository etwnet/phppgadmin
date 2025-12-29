<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Export\FormatterFactory;
use PhpPgAdmin\Gui\ExportOutputRenderer;
use PhpPgAdmin\Gui\QueryDataRenderer;
use PhpPgAdmin\Gui\CompressionFactory;

/**
 * Export query results to various formats (SQL, CSV, XML, HTML, JSON, etc.)
 * Uses unified OutputFormatter infrastructure for consistent format handling.
 *
 * $Id: dataexport.php,v 1.26 2007/07/12 19:26:22 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

$pg = AppContainer::getPostgres();
$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

/**
 * Generate a descriptive filename for the query export.
 */
function generateQueryExportFilename($request)
{
	$timestamp = date('Ymd_His');
	return 'query_export_' . $timestamp;
}

// Handle export action with new unified parameter system
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'export') {
	AppContainer::setSkipHtmlFrame(true);

	// Get unified parameters
	$output_format = $_REQUEST['output_format'] ?? 'csv';
	$insert_format = $_REQUEST['insert_format'] ?? 'copy';

	// Parse composite `output` parameter: 'show' | 'download' | 'download-gzip' | 'download-bzip2' | 'download-zip'
	$rawOutput = $_REQUEST['output'] ?? 'show';
	$parts = array_pad(explode('-', $rawOutput, 2), 2, 'plain');
	$output = ($parts[0] === 'show') ? 'show' : 'download';
	$compToken = strtolower($parts[1]);
	$compMap = [
		'gzip' => 'gzipped',
		'gz' => 'gzipped',
		'gzipped' => 'gzipped',
		'bzip2' => 'bzip2',
		'bz2' => 'bzip2',
		'zip' => 'zip',
		'download' => 'download',
		'plain' => 'download',
		'' => 'download'
	];
	$output_compression = $compMap[$compToken] ?? 'download';

	// Get the query to export
	$query = $_REQUEST['query'] ?? ($_SESSION['sqlquery'] ?? '');
	if (empty($query)) {
		header('HTTP/1.0 400 Bad Request');
		echo "Error: No query provided for export.";
		exit;
	}

	// Validate format is supported for query exports
	try {
		$formatter = FormatterFactory::create($output_format);
	} catch (\Exception $e) {
		header('HTTP/1.0 400 Bad Request');
		echo "Error: Invalid export format: " . htmlspecialchars($output_format);
		exit;
	}

	// Execute the query
	$rs = $pg->conn->Execute($query);
	if (!$rs) {
		header('HTTP/1.0 500 Internal Server Error');
		echo "Error executing query: " . htmlspecialchars($pg->conn->ErrorMsg());
		exit;
	}

	// Set up download headers and output handling
	$filename = generateQueryExportFilename($_REQUEST);
	$mime_type = $formatter->getMimeType();
	$file_extension = $formatter->getFileExtension();

	if ($output === 'show') {
		// For browser display, use unified HTML wrapper
		ExportOutputRenderer::beginHtmlOutput();
		$output_stream = null; // HTML mode uses echo directly
	} else {
		// For all other output methods (download with optional compression), use CompressionFactory
		try {
			$strategy_key = $output_compression ?? 'download';
			$strategy = CompressionFactory::create($strategy_key);
			if (!$strategy) {
				die("Error: Unsupported output method: " . htmlspecialchars($strategy_key));
			}
			$handle = $strategy->begin($filename);
			$output_stream = $handle['stream'];
		} catch (\Exception $e) {
			die("Error: " . htmlspecialchars($e->getMessage()));
		}
	}

	// Reset recordset to beginning for formatter
	$rs->moveFirst();

	// Build metadata for formatter
	$metadata = [
		'table' => $_REQUEST['table'] ?? 'query_result',
		'insert_format' => $insert_format
	];

	// Stream output directly using the formatter
	// Pass stream to formatter for memory-efficient processing
	if ($output_stream !== null) {
		$formatter->setOutputStream($output_stream);
	}
	$formatter->format($rs, $metadata);

	// Close streams for download and gzipped output
	if ($output !== 'show' && isset($strategy) && isset($handle)) {
		$strategy->finish($handle);
	} elseif ($output === 'show') {
		ExportOutputRenderer::endHtmlOutput();
	}
}

// If not an export action, display the export form
// Get query from request or session
$query = $_REQUEST['query'] ?? ($_SESSION['sqlquery'] ?? '');

if (empty($query)) {
	header('HTTP/1.0 400 Bad Request');
	echo "Error: No query provided for export.";
	exit;
}
// Display the query export form
$misc->printHeader($lang['strexport']);
$misc->printBody();
$misc->printTrail($_REQUEST['subject'] ?? 'database');
$misc->printTitle($lang['strexport']);

if (isset($msg))
	$misc->printMsg($msg);

// Render the export form using QueryDataRenderer
$renderer = new QueryDataRenderer();
$renderer->renderExportForm($query, $_REQUEST);

$misc->printFooter();
