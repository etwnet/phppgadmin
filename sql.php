<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\ScriptActions;
use PhpPgAdmin\Database\QueryResult;

/**
 * Process an arbitrary SQL query - tricky!  The main problem is that
 * unless we implement a full SQL parser, there's no way of knowing
 * how many SQL statements have been strung together with semi-colons
 * @param $_SESSION['sqlquery'] The SQL query string to execute
 *
 * $Id: sql.php,v 1.43 2008/01/10 20:19:27 xzilla Exp $
 */

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Render query results to HTML
 * @param QueryResult $result The wrapped query result
 * @param string $query The SQL query that was executed
 */
function renderQueryResult($result, $query)
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	if (!$result->isSuccess) {
		echo nl2br(htmlspecialchars_nc($result->errorMsg)), "<br/>\n";
		return;
	}

	// Get ADODB-compatible adapter (handles both ADODB and pg_* results)
	$rs = $result->getAdapterForResults();

	if ($rs === null) {
		return;
	}

	if ($result->recordCount() > 0) {
		echo "<p><table>\n<tr>";
		foreach ($rs->fields as $k => $v) {
			$finfo = $rs->fetchField($k);
			echo "<th class=\"data\">", $misc->printVal($finfo->name), "</th>";
		}
		echo "</tr>\n";
		$i = 0;
		while (!$rs->EOF) {
			$id = (($i % 2) == 0 ? '1' : '2');
			echo "<tr class=\"data{$id}\">\n";
			foreach ($rs->fields as $k => $v) {
				$finfo = $rs->fetchField($k);
				echo "<td style=\"white-space:nowrap;\">", $misc->printVal($v, $finfo->type, array('null' => true)), "</td>";
			}
			echo "</tr>\n";
			$rs->moveNext();
			$i++;
		}
		echo "</table></p>\n";
		echo "<p>", $i, " {$lang['strrows']}</p>\n";
	} elseif ($result->affectedRows() > 0) {
		echo "<p>", $result->affectedRows(), " {$lang['strrowsaff']}</p>\n";
	} else {
		echo '<p>', $lang['strnodata'], "</p>\n";
	}
}

/**
 * This is a callback function to display the result of each separate query
 * @param string $query The SQL query that was executed
 * @param QueryResult $result The wrapped query result from script executor
 * @param int $lineno The line number in the script file
 */
function sqlCallback($query, $result, $lineno)
{
	//global $_connection;
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();

	// Display query info header
	echo "Line {$lineno}: ";

	if (!$result->isSuccess) {
		echo htmlspecialchars_nc($_FILES['script']['name']), ':', $lineno, ': ', nl2br(htmlspecialchars_nc($result->errorMsg)), "<br/>\n";
	} else {
		// Render the result
		renderQueryResult($result, $query);
	}
}

$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();
$pg = AppContainer::getPostgres();
$schemaActions = new SchemaActions($pg);

/*
sample $_REQUEST contents:
  'query' => string 'SELECT * FROM "public"."actor_info"' (length=35)
  'MAX_FILE_SIZE' => string '2097152' (length=7)
  'target' => string 'content' (length=7)
  'server' => string '127.0.0.1:5432:allow' (length=20)
  'database' => string '' (length=0)
  'search_path' => string 'public' (length=6)
*/

$subject = $_REQUEST['subject'] ?? '';

// We need to store the query in a session for editing purposes
// We avoid GPC vars to avoid truncating long queries
if ($subject == 'history') {
	// Or maybe we came from the history popup
	$_SESSION['sqlquery'] = $_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']][$_GET['queryid']]['query'];
} elseif (isset($_REQUEST['query'])) {
	// Or maybe we came from an sql form
	$_SESSION['sqlquery'] = $_REQUEST['query'];
} else {
	echo "could not find the query!!";
}

// Pagination maybe set by a get link that has it as FALSE,
// if that's the case, unset the variable.

if (isset($_REQUEST['paginate']) && $_REQUEST['paginate'] == 'f') {
	unset($_REQUEST['paginate']);
	unset($_POST['paginate']);
	unset($_GET['paginate']);
}
// Check to see if pagination has been specified. In that case, send to display
// script for pagination
/* if a file is given or the request is an explain, do not paginate */
if (
	isset($_REQUEST['paginate']) && !(isset($_FILES['script']) && $_FILES['script']['size'] > 0)
	&& (preg_match('/^\s*explain/i', $_SESSION['sqlquery']) == 0)
) {
	include('./display.php');
	exit;
}

$misc->printHeader($lang['strqueryresults']);
$misc->printBody();
$misc->printTrail('database');
$misc->printTitle($lang['strqueryresults']);

// Set the schema search path
if (isset($_REQUEST['search_path'])) {
	if ($schemaActions->setSearchPath(array_map('trim', explode(',', $_REQUEST['search_path']))) != 0) {
		$misc->printFooter();
		exit;
	}
}

// May as well try to time the query
$start_time = microtime(true);

// Execute the query.  If it's a script upload, special handling is necessary
if (isset($_FILES['script']) && $_FILES['script']['size'] > 0) {
	// Execute the script via our ScriptActions class
	$scriptActions = new ScriptActions($pg);
	$scriptActions->executeScript('script', 'sqlCallback');
} else {
	// Set fetch mode to NUM so that duplicate field names are properly returned
	$pg->conn->setFetchMode(ADODB_FETCH_NUM);
	$rs = $pg->conn->Execute($_SESSION['sqlquery']);
	$errorMsg = '';

	if ($rs === false) {
		$errorMsg = $pg->conn->ErrorMsg();
	}

	// Wrap result for consistent handling
	$result = QueryResult::fromADORecordSet($rs, $errorMsg);

	// Request was run, saving it in history
	if ($rs !== false && !isset($_REQUEST['nohistory']))
		$misc->saveSqlHistory($_SESSION['sqlquery'], false);

	// Render the result
	renderQueryResult($result, $_SESSION['sqlquery']);
}

// May as well try to time the query
$end_time = microtime(true);
$duration = number_format(($end_time - $start_time) * 1000, 3);

// Reload the tree as we may have made schema changes
// Todo: refine this to only reload on changes
AppContainer::setShouldReloadTree(true);

// Display duration if we know it
if ($duration !== null) {
	echo "<p>", sprintf($lang['strruntime'], $duration), "</p>\n";
}

echo "<p>{$lang['strsqlexecuted']}</p>\n";

$navlinks = array();
$fields = array(
	'server' => $_REQUEST['server'],
	'database' => $_REQUEST['database'],
);

if (isset($_REQUEST['schema']))
	$fields['schema'] = $_REQUEST['schema'];

// Return
if (isset($_REQUEST['return'])) {
	$urlvars = $misc->getSubjectParams($_REQUEST['return']);
	$navlinks['back'] = array(
		'attr' => array(
			'href' => array(
				'url' => $urlvars['url'],
				'urlvars' => $urlvars['params']
			)
		),
		'content' => $lang['strback']
	);
}

// Edit		
$navlinks['alter'] = array(
	'attr' => array(
		'href' => array(
			'url' => 'database.php',
			'urlvars' => array_merge($fields, array(
				'action' => 'sql',
			))
		)
	),
	'icon' => $misc->icon('SqlEditor'),
	'content' => $lang['streditsql']
);

// Create view and download
if (isset($_SESSION['sqlquery']) && isset($rs) && is_object($rs) && $rs->recordCount() > 0) {
	// Report views don't set a schema, so we need to disable create view in that case
	if (isset($_REQUEST['schema'])) {
		$navlinks['createview'] = array(
			'attr' => array(
				'href' => array(
					'url' => 'views.php',
					'urlvars' => array_merge($fields, array(
						'action' => 'create'
					))
				)
			),
			'content' => $lang['strcreateview']
		);
	}

	if (isset($_REQUEST['search_path']))
		$fields['search_path'] = $_REQUEST['search_path'];

	$navlinks['download'] = array(
		'attr' => array(
			'href' => array(
				'url' => 'dataexport.php',
				'urlvars' => $fields
			)
		),
		'icon' => $misc->icon('Download'),
		'content' => $lang['strdownload']
	);
}

$misc->printNavLinks($navlinks, 'sql-form', get_defined_vars());

$misc->printFooter();
