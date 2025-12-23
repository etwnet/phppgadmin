<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Database\DumpManager;
use PhpPgAdmin\Database\Dump\DumpFactory;
use PhpPgAdmin\Database\Actions\TableActions;

/**
 * Does an export to the screen or as a download.  This checks to
 * see if they have pg_dump set up, and will use it if possible.
 * Falls back to PHP-based export if pg_dump is unavailable.
 *
 * $Id: dataexport.php,v 1.26 2007/07/12 19:26:22 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

$extensions = [
	'sql' => 'sql',
	'copy' => 'sql',
	'csv' => 'csv',
	'tab' => 'txt',
	'html' => 'html',
	'xml' => 'xml'
];

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

$pg = AppContainer::getPostgres();
$misc = AppContainer::getMisc();

// if (!isset($_REQUEST['table']) && !isset($_REQUEST['query']))
// What must we do in this case? Maybe redirect to the homepage?

// If format is set, then perform the export
if (isset($_REQUEST['what'])) {

	// Include application functions
	AppContainer::setSkipHtmlFrame(true);

	// Determine the format being exported
	$what = $_REQUEST['what'];

	switch ($what) {
		case 'dataonly':
			$format = $_REQUEST['d_format'] ?? 'csv';
			$oids = isset($_REQUEST['d_oids']);
			$clean = false;
			break;
		case 'structureonly':
			$format = $_REQUEST['s_format'] ?? 'sql';
			$oids = false;
			$clean = isset($_REQUEST['s_clean']);
			break;
		case 'structureanddata':
			$format = $_REQUEST['sd_format'] ?? 'csv';
			$oids = isset($_REQUEST['sd_oids']);
			$clean = isset($_REQUEST['sd_clean']);
			break;
		default:
			echo "Error: Unknown export type: " . htmlspecialchars($what);
			exit;
	}

	// Use internal dumper for SQL/COPY formats if requested or as fallback
	if (in_array($format, ['sql', 'copy'])) {
		$use_internal = isset($_REQUEST['use_internal']) || empty(DumpManager::getDumpExecutable(false));

		if ($use_internal) {
			$subject = $_REQUEST['subject'] ?? 'table';
			$params = [
				'table' => $_REQUEST['table'] ?? null,
				'schema' => $_REQUEST['schema'] ?? $pg->_schema,
				'database' => $_REQUEST['database'] ?? null
			];
			$options = [
				'format' => $format,
				'oids' => $oids,
				'clean' => $clean,
				'if_not_exists' => isset($_REQUEST['if_not_exists']),
				'structure_only' => ($what === 'structureonly'),
				'data_only' => ($what === 'dataonly')
			];

			// Set headers for download if necessary
			if ($_REQUEST['output'] == 'download') {
				header('Content-Type: application/download');
				header('Content-Disposition: attachment; filename=dump.sql');
			} else {
				header('Content-Type: text/plain');
			}

			$dumper = DumpFactory::create($subject, $pg);
			$dumper->dump($subject, $params, $options);
			exit;
		}
	}

	// Validate that the requested format is available
	$status = $pg->beginDump();

	// If the dump is not dataonly then dump the structure prefix
	if ($_REQUEST['what'] != 'dataonly') {
		$tableActions = new TableActions($pg);
		echo $tableActions->getTableDefPrefix($_REQUEST['table'], $clean);
	}

	// If the dump is not structureonly then dump the actual data
	if ($_REQUEST['what'] != 'structureonly') {
		// Get database encoding
		$databaseActions = new DatabaseActions($pg);
		$dbEncoding = $databaseActions->getDatabaseEncoding();

		// Set fetch mode to NUM so that duplicate field names are properly returned
		$pg->conn->setFetchMode(ADODB_FETCH_NUM);

		// Execute the query, if set, otherwise grab all rows from the table
		if (isset($_REQUEST['table']))
			$rs = $pg->dumpRelation($_REQUEST['table'], $oids);
		else
			$rs = $pg->conn->Execute($_REQUEST['query']);

		if ($format == 'copy') {
			$pg->fieldClean($_REQUEST['table']);
			echo "COPY \"{$_REQUEST['table']}\"";
			if ($oids)
				echo " WITH OIDS";
			echo " FROM stdin;\n";
			while (!$rs->EOF) {
				$first = true;
				foreach ($rs->fields as $k => $v) {
					// Escape value
					$v = $pg->escapeBytea($v);

					// We add an extra escaping slash onto octal encoded characters
					$v = preg_replace('/\\\\([0-7]{3})/', '\\\\\1', $v);
					if ($first) {
						echo (is_null($v)) ? '\\N' : $v;
						$first = false;
					} else
						echo "\t", (is_null($v)) ? '\\N' : $v;
				}
				echo "\n";
				$rs->moveNext();
			}
			echo "\\.\n";
		} elseif ($format == 'html') {
			echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">\r\n";
			echo "<html xmlns=\"http://www.w3.org/1999/xhtml\">\r\n";
			echo "<head>\r\n";
			echo "\t<title></title>\r\n";
			echo "\t<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />\r\n";
			echo "</head>\r\n";
			echo "<body>\r\n";
			echo "<table class=\"phppgadmin\">\r\n";
			echo "\t<tr>\r\n";
			if (!$rs->EOF) {
				// Output header row
				$j = 0;
				foreach ($rs->fields as $k => $v) {
					$finfo = $rs->fetchField($j++);
					if ($finfo->name == $pg->id && !$oids)
						continue;
					echo "\t\t<th>", $misc->printVal($finfo->name, true), "</th>\r\n";
				}
			}
			echo "\t</tr>\r\n";
			while (!$rs->EOF) {
				echo "\t<tr>\r\n";
				$j = 0;
				foreach ($rs->fields as $k => $v) {
					$finfo = $rs->fetchField($j++);
					if ($finfo->name == $pg->id && !$oids)
						continue;
					echo "\t\t<td>", $misc->printVal($v, true, $finfo->type), "</td>\r\n";
				}
				echo "\t</tr>\r\n";
				$rs->moveNext();
			}
			echo "</table>\r\n";
			echo "</body>\r\n";
			echo "</html>\r\n";
		} elseif ($format == 'xml') {
			echo "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n";
			echo "<data>\n";
			if (!$rs->EOF) {
				// Output header row
				$j = 0;
				echo "\t<header>\n";
				foreach ($rs->fields as $k => $v) {
					$finfo = $rs->fetchField($j++);
					$name = htmlspecialchars_nc($finfo->name);
					$type = htmlspecialchars_nc($finfo->type);
					echo "\t\t<column name=\"{$name}\" type=\"{$type}\" />\n";
				}
				echo "\t</header>\n";
			}
			echo "\t<records>\n";
			while (!$rs->EOF) {
				$j = 0;
				echo "\t\t<row>\n";
				foreach ($rs->fields as $k => $v) {
					$finfo = $rs->fetchField($j++);
					$name = htmlspecialchars_nc($finfo->name);
					if (!is_null($v))
						$v = htmlspecialchars_nc($v);
					echo "\t\t\t<column name=\"{$name}\"", (is_null($v) ? ' null="null"' : ''), ">{$v}</column>\n";
				}
				echo "\t\t</row>\n";
				$rs->moveNext();
			}
			echo "\t</records>\n";
			echo "</data>\n";
		} elseif ($format == 'sql') {
			$pg->fieldClean($_REQUEST['table']);
			while (!$rs->EOF) {
				echo "INSERT INTO \"{$_REQUEST['table']}\" (";
				$first = true;
				$j = 0;
				foreach ($rs->fields as $k => $v) {
					$finfo = $rs->fetchField($j++);
					$k = $finfo->name;
					// SQL (INSERT) format cannot handle oids
					//						if ($k == $pg->id) continue;
					// Output field
					$pg->fieldClean($k);
					if ($first)
						echo "\"{$k}\"";
					else
						echo ", \"{$k}\"";

					if (!is_null($v)) {
						// Output value
						// addCSlashes converts all weird ASCII characters to octal representation,
						// EXCEPT the 'special' ones like \r \n \t, etc.
						$v = addCSlashes($v, "\0..\37\177..\377");
						// We add an extra escaping slash onto octal encoded characters
						$v = preg_replace('/\\\\([0-7]{3})/', '\\\1', $v);
						// Finally, escape all apostrophes
						$v = str_replace("'", "''", $v);
					}
					if ($first) {
						$values = (is_null($v) ? 'NULL' : "'{$v}'");
						$first = false;
					} else
						$values .= ', ' . ((is_null($v) ? 'NULL' : "'{$v}'"));
				}
				echo ") VALUES ({$values});\n";
				$rs->moveNext();
			}
		} else {
			switch ($format) {
				case 'tab':
					$sep = "\t";
					break;
				case 'csv':
				default:
					$sep = ',';
					break;
			}
			if (!$rs->EOF) {
				// Output header row
				$first = true;
				foreach ($rs->fields as $k => $v) {
					$finfo = $rs->fetchField($k);
					$v = $finfo->name;
					if (!is_null($v))
						$v = str_replace('"', '""', $v);
					if ($first) {
						echo "\"{$v}\"";
						$first = false;
					} else
						echo "{$sep}\"{$v}\"";
				}
				echo "\r\n";
			}
			while (!$rs->EOF) {
				$first = true;
				foreach ($rs->fields as $k => $v) {
					if (!is_null($v))
						$v = str_replace('"', '""', $v);
					if ($first) {
						echo (is_null($v)) ? "\"\\N\"" : "\"{$v}\"";
						$first = false;
					} else
						echo is_null($v) ? "{$sep}\"\\N\"" : "{$sep}\"{$v}\"";
				}
				echo "\r\n";
				$rs->moveNext();
			}
		}
	}

	// If the dump is not dataonly then dump the structure suffix
	if ($_REQUEST['what'] != 'dataonly') {
		// Set fetch mode back to ASSOC for the table suffix to work
		$pg->conn->setFetchMode(ADODB_FETCH_ASSOC);
		$tableActions = new TableActions($pg);
		echo $tableActions->getTableDefSuffix($_REQUEST['table']);
	}

	// Finish the dump transaction
	$status = $pg->endDump();
} else {

	if (!isset($_REQUEST['query']) or empty($_REQUEST['query']))
		$_REQUEST['query'] = $_SESSION['sqlquery'];

	$misc->printHeader($lang['strexport']);
	$misc->printBody();
	$misc->printTrail($_REQUEST['subject'] ?? 'database');
	$misc->printTitle($lang['strexport']);
	if (isset($msg))
		$misc->printMsg($msg);

	// Get available formats based on current dump situation
	$available_formats = DumpManager::getAvailableFormats(false, 'dataonly');
	$has_pg_dump = $available_formats['has_pg_dump'];
	$available_list = $available_formats['available'];

	// Show info about dump status
	$dump_info = DumpManager::getDumpStrategyInfo(false);
	if (!$has_pg_dump) {
		echo "<div style=\"background-color: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin-bottom: 15px; border-radius: 4px;\">\n";
		echo "<strong>Note:</strong> " . htmlspecialchars($dump_info['message']) . "\n";
		echo "</div>\n";
	}

	echo "<form action=\"dataexport.php\" method=\"post\">\n";
	echo "<table>\n";
	echo "<tr><th class=\"data\">{$lang['strformat']}:</th><td><select name=\"d_format\">\n";

	// COPY and SQL require a table and pg_dump
	if (isset($_REQUEST['table'])) {
		if (in_array('copy', $available_list)) {
			echo "<option value=\"copy\">COPY</option>\n";
		}
		if (in_array('sql', $available_list)) {
			echo "<option value=\"sql\">SQL</option>\n";
		}
	}

	// CSV/TAB/HTML/XML always available
	if (in_array('csv', $available_list)) {
		echo "<option value=\"csv\">CSV</option>\n";
	}
	if (in_array('tab', $available_list)) {
		echo "<option value=\"tab\">{$lang['strtabbed']}</option>\n";
	}
	if (in_array('html', $available_list)) {
		echo "<option value=\"html\">XHTML</option>\n";
	}
	if (in_array('xml', $available_list)) {
		echo "<option value=\"xml\">XML</option>\n";
	}

	echo "</select></td></tr>";
	echo "</table>\n";

	echo "<h3>{$lang['stroptions']}</h3>\n";
	echo "<p><input type=\"checkbox\" id=\"s_clean\" name=\"s_clean\" value=\"true\" /><label for=\"s_clean\">{$lang['strdrop']}</label>\n";
	echo "<br/><input type=\"checkbox\" id=\"if_not_exists\" name=\"if_not_exists\" value=\"true\" /><label for=\"if_not_exists\">Use IF NOT EXISTS</label>\n";
	echo "<br/><input type=\"checkbox\" id=\"use_internal\" name=\"use_internal\" value=\"true\" /><label for=\"use_internal\">Force internal PHP dumper</label></p>\n";

	echo "<p><input type=\"radio\" id=\"output1\" name=\"output\" value=\"show\" checked=\"checked\" /><label for=\"output1\">{$lang['strshow']}</label>\n";
	echo "<br/><input type=\"radio\" id=\"output2\" name=\"output\" value=\"download\" /><label for=\"output2\">{$lang['strdownload']}</label></p>\n";

	echo "<p><input type=\"hidden\" name=\"action\" value=\"export\" />\n";
	echo "<input type=\"hidden\" name=\"what\" value=\"dataonly\" />\n";
	if (isset($_REQUEST['table'])) {
		echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars_nc($_REQUEST['table']), "\" />\n";
	}
	echo "<input type=\"hidden\" name=\"query\" value=\"", htmlspecialchars_nc(urlencode($_REQUEST['query'])), "\" />\n";
	if (isset($_REQUEST['search_path'])) {
		echo "<input type=\"hidden\" name=\"search_path\" value=\"", htmlspecialchars_nc($_REQUEST['search_path']), "\" />\n";
	}
	echo $misc->form;
	echo "<input type=\"submit\" value=\"{$lang['strexport']}\" /></p>\n";
	echo "</form>\n";

	$misc->printFooter();
}
