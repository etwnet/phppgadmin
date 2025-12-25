<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ColumnActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Gui\DumpRenderer;

/**
 * List tables in a database
 *
 * $Id: tblproperties.php,v 1.92 2008/01/19 13:46:15 ioguix Exp $
 */

// Include application functions
include_once './libraries/bootstrap.php';

/**
 * Function to save after altering a table
 */
function doSaveAlter()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$misc = AppContainer::getMisc();
	$tableActions = new TableActions($pg);

	// For databases that don't allow owner change
	if (!isset($_POST['owner'])) {
		$_POST['owner'] = '';
	}

	// Default tablespace to null if it isn't set
	if (!isset($_POST['tablespace'])) {
		$_POST['tablespace'] = null;
	}

	if (!isset($_POST['newschema'])) {
		$_POST['newschema'] = null;
	}

	$status = $tableActions->alterTable(
		$_POST['table'],
		$_POST['name'],
		$_POST['owner'],
		$_POST['newschema'],
		$_POST['comment'],
		$_POST['tablespace']
	);

	if ($status != 0) {
		doAlter($lang['strtablealteredbad']);
		return;
	}

	// If table has been renamed, need to change to the new name and
	// reload the browser frame.
	if ($_POST['table'] != $_POST['name']) {
		// Jump them to the new table name
		$_REQUEST['table'] = $_POST['name'];
		// Force a browser reload
		AppContainer::setShouldReloadTree(true);
	}
	// If schema has changed, need to change to the new schema and reload the browser
	if (!empty($_POST['newschema']) && ($_POST['newschema'] != $pg->_schema)) {
		// Jump them to the new sequence schema
		$misc->setCurrentSchema($_POST['newschema']);
		AppContainer::setShouldReloadTree(true);
	}
	doDefault($lang['strtablealtered']);

}

/**
 * Function to allow altering of a table
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);
	$tableActions = new TableActions($pg);
	$schemaActions = new SchemaActions($pg);
	$tablespaceActions = new TablespaceActions($pg);

	$misc->printTrail('table');
	$misc->printTitle($lang['stralter'], 'pg.table.alter');
	$misc->printMsg($msg);

	// Fetch table info
	$table = $tableActions->getTable($_REQUEST['table']);
	// Fetch all users
	$users = $roleActions->getUsers();
	// Fetch all tablespaces from the database
	if ($pg->hasTablespaces()) {
		$tablespaces = $tablespaceActions->getTablespaces(true);
	}

	if ($table->recordCount() == 0) {
		echo "<p class=\"empty\">{$lang['strnodata']}</p>\n";
		return;
	}

	if (!isset($_POST['name'])) {
		$_POST['name'] = $table->fields['relname'];
	}

	if (!isset($_POST['owner'])) {
		$_POST['owner'] = $table->fields['relowner'];
	}

	if (!isset($_POST['newschema'])) {
		$_POST['newschema'] = $table->fields['nspname'];
	}

	if (!isset($_POST['comment'])) {
		$_POST['comment'] = $table->fields['relcomment'];
	}

	if ($pg->hasTablespaces() && !isset($_POST['tablespace'])) {
		$_POST['tablespace'] = $table->fields['tablespace'];
	}

	echo "<form action=\"tblproperties.php\" method=\"post\">\n";
	echo "<table>\n";
	echo "<tr><th class=\"data left required\">{$lang['strname']}</th>\n";
	echo "<td class=\"data1\">";
	echo "<input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		htmlspecialchars_nc($_POST['name'], ENT_QUOTES), "\" /></td></tr>\n";

	if ($roleActions->isSuperUser()) {
		echo "<tr><th class=\"data left required\">{$lang['strowner']}</th>\n";
		echo "<td class=\"data1\"><select name=\"owner\">";
		while (!$users->EOF) {
			$uname = $users->fields['usename'];
			echo "<option value=\"", htmlspecialchars_nc($uname), "\"", ($uname == $_POST['owner']) ? ' selected="selected"' : '', ">", htmlspecialchars_nc($uname), "</option>\n";
			$users->moveNext();
		}
		echo "</select></td></tr>\n";
	}

	if ($pg->hasAlterTableSchema()) {
		$schemas = $schemaActions->getSchemas();
		echo "<tr><th class=\"data left required\">{$lang['strschema']}</th>\n";
		echo "<td class=\"data1\"><select name=\"newschema\">";
		while (!$schemas->EOF) {
			$schema = $schemas->fields['nspname'];
			echo "<option value=\"", htmlspecialchars_nc($schema), "\"", ($schema == $_POST['newschema']) ? ' selected="selected"' : '', ">", htmlspecialchars_nc($schema), "</option>\n";
			$schemas->moveNext();
		}
		echo "</select></td></tr>\n";
	}

	// Tablespace (if there are any)
	if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0) {
		echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strtablespace']}</th>\n";
		echo "\t\t<td class=\"data1\">\n\t\t\t<select name=\"tablespace\">\n";
		// Always offer the default (empty) option
		echo "\t\t\t\t<option value=\"\"", ($_POST['tablespace'] == '') ? ' selected="selected"' : '', "></option>\n";
		// Display all other tablespaces
		while (!$tablespaces->EOF) {
			$spcname = htmlspecialchars_nc($tablespaces->fields['spcname']);
			echo "\t\t\t\t<option value=\"{$spcname}\"", ($spcname == $_POST['tablespace']) ? ' selected="selected"' : '', ">{$spcname}</option>\n";
			$tablespaces->moveNext();
		}
		echo "\t\t\t</select>\n\t\t</td>\n\t</tr>\n";
	}

	echo "<tr><th class=\"data left\">{$lang['strcomment']}</th>\n";
	echo "<td class=\"data1\">";
	echo "<textarea rows=\"3\" cols=\"32\" name=\"comment\">",
		htmlspecialchars_nc($_POST['comment'] ?? ''), "</textarea></td></tr>\n";
	echo "</table>\n";
	echo "<p><input type=\"hidden\" name=\"action\" value=\"alter\" />\n";
	echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars_nc($_REQUEST['table']), "\" />\n";
	echo $misc->form;
	echo "<input type=\"submit\" name=\"alter\" value=\"{$lang['stralter']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
	echo "</form>\n";

}

function doExport($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printTrail('table');
	$misc->printTabs('table', 'export');
	$misc->printMsg($msg);

	// Use the unified DumpRenderer for the export form
	$dumpRenderer = new \PhpPgAdmin\Gui\DumpRenderer();
	$dumpRenderer->renderExportForm('table', []);
}

function doImport($msg = '')
{
	//$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printTrail('table');
	$misc->printTabs('table', 'import');
	$misc->printMsg($msg);

	// Check that file uploads are enabled
	if (!ini_get('file_uploads')) {
		echo "<p>{$lang['strnouploads']}</p>\n";
		return;
	}

	// Don't show upload option if max size of uploads is zero
	$max_size = $misc->inisizeToBytes(ini_get('upload_max_filesize'));
	if (!is_double($max_size) || $max_size == 0) {
		return;
	}

	echo "<form action=\"dataimport.php\" method=\"post\" enctype=\"multipart/form-data\">\n";
	echo "<table>\n";
	echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strformat']}</th>\n";
	echo "\t\t<td><select name=\"format\">\n";
	echo "\t\t\t<option value=\"auto\">{$lang['strauto']}</option>\n";
	echo "\t\t\t<option value=\"csv\">CSV</option>\n";
	echo "\t\t\t<option value=\"tab\">{$lang['strtabbed']}</option>\n";
	if (function_exists('xml_parser_create')) {
		echo "\t\t\t<option value=\"xml\">XML</option>\n";
	}
	echo "\t\t</select></td>\n\t</tr>\n";
	echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strallowednulls']}</th>\n";
	echo "\t\t<td><label><input type=\"checkbox\" name=\"allowednulls[0]\" value=\"\\N\" checked=\"checked\" />{$lang['strbackslashn']}</label><br />\n";
	echo "\t\t<label><input type=\"checkbox\" name=\"allowednulls[1]\" value=\"NULL\" />NULL</label><br />\n";
	echo "\t\t<label><input type=\"checkbox\" name=\"allowednulls[2]\" value=\"\" />{$lang['stremptystring']}</label></td>\n\t</tr>\n";
	echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strfile']}</th>\n";
	echo "\t\t<td><input type=\"hidden\" name=\"MAX_FILE_SIZE\" value=\"{$max_size}\" />";
	echo "<input type=\"file\" name=\"source\" /></td>\n\t</tr>\n";
	echo "</table>\n";
	echo "<p><input type=\"hidden\" name=\"action\" value=\"import\" />\n";
	echo $misc->form;
	echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars_nc($_REQUEST['table']), "\" />\n";
	echo "<input type=\"submit\" value=\"{$lang['strimport']}\" /></p>\n";
	echo "</form>\n";
}

/**
 * Displays a screen where they can add a column
 */
function doAddColumn($msg = '')
{

	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);
	$columnActions = new ColumnActions($pg);

	if (!isset($_REQUEST['stage'])) {
		$_REQUEST['stage'] = 1;
	}

	switch ($_REQUEST['stage']) {
		case 1:
			// Set variable defaults
			if (!isset($_POST['field'])) {
				$_POST['field'] = '';
			}

			if (!isset($_POST['type'])) {
				$_POST['type'] = '';
			}

			if (!isset($_POST['array'])) {
				$_POST['array'] = '';
			}

			if (!isset($_POST['length'])) {
				$_POST['length'] = '';
			}

			if (!isset($_POST['default'])) {
				$_POST['default'] = '';
			}

			if (!isset($_POST['comment'])) {
				$_POST['comment'] = '';
			}

			// Fetch all available types
			$types = $typeActions->getTypes(true, false, true);
			$types_for_js = [];

			$misc->printTrail('table');
			$misc->printTitle($lang['straddcolumn'], 'pg.column.add');
			$misc->printMsg($msg);

			echo "<script src=\"js/tables.js\" type=\"text/javascript\"></script>";
			echo "<form action=\"tblproperties.php\" method=\"post\">\n";

			// Output table header
			echo "<table>\n";
			echo "<tr><th class=\"data required\">{$lang['strname']}</th>\n<th colspan=\"2\" class=\"data required\">{$lang['strtype']}</th>\n";
			echo "<th class=\"data\">{$lang['strlength']}</th>\n";
			if ($pg->hasCreateFieldWithConstraints()) {
				echo "<th class=\"data\">{$lang['strnotnull']}</th>\n<th class=\"data\">{$lang['strdefault']}</th>\n";
			}

			echo "<th class=\"data\">{$lang['strcomment']}</th></tr>\n";

			echo "<tr><td><input name=\"field\" size=\"16\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				htmlspecialchars_nc($_POST['field']), "\" /></td>\n";
			echo "<td><select name=\"type\" id=\"type\" onchange=\"checkLengths(document.getElementById('type').value,'');\">\n";
			// Output any "magic" types.  This came in with the alter column type so we'll check that
			if ($pg->hasMagicTypes()) {
				foreach ($pg->extraTypes as $v) {
					$types_for_js[] = strtolower($v);
					echo "\t<option value=\"", htmlspecialchars_nc($v), "\"", ($v == $_POST['type']) ? ' selected="selected"' : '', ">",
						$misc->printVal($v), "</option>\n";
				}
			}
			while (!$types->EOF) {
				$typname = $types->fields['typname'];
				$types_for_js[] = $typname;
				echo "\t<option value=\"", htmlspecialchars_nc($typname), "\"", ($typname == $_POST['type']) ? ' selected="selected"' : '', ">",
					$misc->printVal($typname), "</option>\n";
				$types->moveNext();
			}
			echo "</select></td>\n";

			// Output array type selector
			echo "<td><select name=\"array\">\n";
			echo "\t<option value=\"\"", ($_POST['array'] == '') ? ' selected="selected"' : '', "></option>\n";
			echo "\t<option value=\"[]\"", ($_POST['array'] == '[]') ? ' selected="selected"' : '', ">[ ]</option>\n";
			echo "</select></td>\n";
			$predefined_size_types = array_intersect($pg->predefined_size_types, $types_for_js);
			$escaped_predef_types = []; // the JS escaped array elements
			foreach ($predefined_size_types as $value) {
				$escaped_predef_types[] = "'{$value}'";
			}

			echo "<td><input name=\"length\" id=\"lengths\" size=\"8\" value=\"",
				htmlspecialchars_nc($_POST['length']), "\" /></td>\n";
			// Support for adding column with not null and default
			if ($pg->hasCreateFieldWithConstraints()) {
				echo "<td><input type=\"checkbox\" name=\"notnull\"", (isset($_REQUEST['notnull'])) ? ' checked="checked"' : '', " /></td>\n";
				echo "<td><input name=\"default\" size=\"20\" value=\"",
					htmlspecialchars_nc($_POST['default']), "\" /></td>\n";
			}
			echo "<td><input name=\"comment\" size=\"40\" value=\"",
				htmlspecialchars_nc($_POST['comment']), "\" /></td></tr>\n";
			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"add_column\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars_nc($_REQUEST['table']), "\" />\n";
			if (!$pg->hasCreateFieldWithConstraints()) {
				echo "<input type=\"hidden\" name=\"default\" value=\"\" />\n";
			}
			echo "<input type=\"submit\" value=\"{$lang['stradd']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			echo "<script type=\"text/javascript\">predefined_lengths = new Array(" . implode(",", $escaped_predef_types) . ");checkLengths(document.getElementById('type').value,'');</script>\n";
			break;
		case 2:
			// Check inputs
			if (trim($_POST['field']) == '') {
				$_REQUEST['stage'] = 1;
				doAddColumn($lang['strcolneedsname']);
				return;
			}
			if (!isset($_POST['length'])) {
				$_POST['length'] = '';
			}

			$status = $columnActions->addColumn(
				$_POST['table'],
				$_POST['field'],
				$_POST['type'],
				$_POST['array'] != '',
				$_POST['length'],
				isset($_POST['notnull']),
				$_POST['default'],
				$_POST['comment']
			);
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strcolumnadded']);
			} else {
				$_REQUEST['stage'] = 1;
				doAddColumn($lang['strcolumnaddedbad']);
				return;
			}
			break;
		default:
			echo "<p>{$lang['strinvalidparam']}</p>\n";
	}
}

/**
 * Show confirmation of drop column and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);

	if ($confirm) {
		$misc->printTrail('column');
		$misc->printTitle($lang['strdrop'], 'pg.column.drop');

		echo "<p>", sprintf(
			$lang['strconfdropcolumn'],
			$misc->printVal($_REQUEST['column']),
			$misc->printVal($_REQUEST['table'])
		), "</p>\n";

		echo "<form action=\"tblproperties.php\" method=\"post\">\n";
		echo "<input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars_nc($_REQUEST['table']), "\" />\n";
		echo "<input type=\"hidden\" name=\"column\" value=\"", htmlspecialchars_nc($_REQUEST['column']), "\" />\n";
		echo $misc->form;
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\"> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} else {
		$status = $columnActions->dropColumn($_POST['table'], $_POST['column'], isset($_POST['cascade']));
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strcolumndropped']);
		} else {
			doDefault($lang['strcolumndroppedbad']);
		}

	}
}

/**
 * Show default list of columns in the table
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$constraintActions = new ConstraintActions($pg);

	$attPre = function ($rowdata, $actions) {
		$pg = AppContainer::getPostgres();
		$rowdata->fields['+type'] = $pg->formatType($rowdata->fields['type'], $rowdata->fields['atttypmod']);
		$attname = $rowdata->fields['attname'];
		$table = $_REQUEST['table'];
		$pg->fieldClean($attname);
		$pg->fieldClean($table);

		$actions['browse']['attr']['href']['urlvars']['query'] =
			"SELECT \"{$attname}\", count(*) AS \"count\"
				FROM \"{$table}\" GROUP BY \"{$attname}\" ORDER BY \"{$attname}\"";

		return $actions;
	};

	$cstrRender = function ($s, $p) {
		$misc = AppContainer::getMisc();
		$pg = AppContainer::getPostgres();
		$tableActions = new TableActions($pg);

		$str = '';
		foreach ($p['keys'] as $k => $c) {

			if (is_null($p['keys'][$k]['consrc'])) {
				$atts = $tableActions->getAttributeNames($_REQUEST['table'], explode(' ', $p['keys'][$k]['indkey']));
				$c['consrc'] = ($c['contype'] == 'u' ? "UNIQUE (" : "PRIMARY KEY (") . join(',', $atts) . ')';
			}

			if ($c['p_field'] != $s) {
				continue;
			}

			switch ($c['contype']) {
				case 'p':
					$str .= '<a href="constraints.php?' . $misc->href . "&amp;table=" . urlencode($c['p_table']) . "&amp;schema=" . urlencode($c['p_schema']) . "\"><img src=\"" .
						$misc->icon('PrimaryKey') . '" alt="[pk]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
					break;
				case 'f':
					$str .= '<a href="tblproperties.php?' . $misc->href . "&amp;table=" . urlencode($c['f_table']) . "&amp;schema=" . urlencode($c['f_schema']) . "\"><img src=\"" .
						$misc->icon('ForeignKey') . '" alt="[fk]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
					break;
				case 'u':
					$str .= '<a href="constraints.php?' . $misc->href . "&amp;table=" . urlencode($c['p_table']) . "&amp;schema=" . urlencode($c['p_schema']) . "\"><img src=\"" .
						$misc->icon('UniqueConstraint') . '" alt="[uniq]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
					break;
				case 'c':
					$str .= '<a href="constraints.php?' . $misc->href . "&amp;table=" . urlencode($c['p_table']) . "&amp;schema=" . urlencode($c['p_schema']) . "\"><img src=\"" .
						$misc->icon('CheckConstraint') . '" alt="[check]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
			}

		}

		return $str;
	};

	$misc->printTrail('table');
	$misc->printTabs('table', 'columns');
	$misc->printMsg($msg);

	// Get table
	$tdata = $tableActions->getTable($_REQUEST['table']);
	// Get columns
	$attrs = $tableActions->getTableAttributes($_REQUEST['table']);
	// Get constraints keys
	$ck = $constraintActions->getConstraintsWithFields($_REQUEST['table']);

	// Show comment if any
	if ($tdata->fields['relcomment'] !== null) {
		echo '<p class="comment">', $misc->printVal($tdata->fields['relcomment']), "</p>\n";
	}

	$columns = [
		'column' => [
			'title' => $lang['strcolumn'],
			'field' => field('attname'),
			'url' => "colproperties.php?subject=column&amp;{$misc->href}&amp;table=" . urlencode($_REQUEST['table']) . "&amp;",
			'vars' => ['column' => 'attname'],
			'icon' => $misc->icon('Column'),
			'class' => 'no-wrap',
		],
		'type' => [
			'title' => $lang['strtype'],
			'field' => field('+type'),
		],
		'notnull' => [
			'title' => $lang['strnotnull'],
			'field' => field('attnotnull'),
			'type' => 'bool',
			'params' => ['true' => 'NOT NULL', 'false' => ''],
		],
		'default' => [
			'title' => $lang['strdefault'],
			'field' => field('adsrc'),
		],
		'keyprop' => [
			'title' => $lang['strconstraints'],
			'class' => 'constraint_cell',
			'field' => field('attname'),
			'type' => 'callback',
			'params' => [
				'function' => $cstrRender,
				'keys' => $ck->getArray(),
			],
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('comment'),
		],
	];

	$actions = [
		'browse' => [
			'icon' => $misc->icon('Table'),
			'content' => $lang['strbrowse'],
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'table' => $_REQUEST['table'],
						'subject' => 'column',
						'return' => 'table',
						'column' => field('attname'),
					],
				],
			],
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit'],
			'attr' => [
				'href' => [
					'url' => 'colproperties.php',
					'urlvars' => [
						'subject' => 'column',
						'action' => 'properties',
						'table' => $_REQUEST['table'],
						'column' => field('attname'),
					],
				],
			],
		],
		'privileges' => [
			'icon' => $misc->icon('Privileges'),
			'content' => $lang['strprivileges'],
			'attr' => [
				'href' => [
					'url' => 'privileges.php',
					'urlvars' => [
						'subject' => 'column',
						'table' => $_REQUEST['table'],
						'column' => field('attname'),
					],
				],
			],
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'subject' => 'column',
						'action' => 'confirm_drop',
						'table' => $_REQUEST['table'],
						'column' => field('attname'),
					],
				],
			],
		],
	];

	$misc->printTable($attrs, $columns, $actions, 'tblproperties-tblproperties', null, $attPre);

	$navlinks = [
		'browse' => [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
						'subject' => 'table',
						'return' => 'table',
					],
				],
			],
			'icon' => $misc->icon('Table'),
			'content' => $lang['strbrowse'],
		],
		'select' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confselectrows',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Search'),
			'content' => $lang['strselect'],
		],
		'insert' => [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'action' => 'confinsertrow',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
						'subject' => 'table',
					],
				],
			],
			'icon' => $misc->icon('Add'),
			'content' => $lang['strinsert'],
		],
		'empty' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_empty',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Shredder'),
			'content' => $lang['strempty'],
		],
		'drop' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
		],
		'addcolumn' => [
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'action' => 'add_column',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('AddColumn'),
			'content' => $lang['straddcolumn'],
		],
		'alter' => [
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit'],
		],
	];
	$misc->printNavLinks(
		$navlinks,
		'tblproperties-tblproperties',
		get_defined_vars()
	);
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$tableActions = new TableActions($pg);

	$columns = $tableActions->getTableAttributes($_REQUEST['table']);
	$reqvars = $misc->getRequestVars('column');

	$attrs = [
		'text' => field('attname'),
		'action' => url(
			'colproperties.php',
			$reqvars,
			[
				'table' => $_REQUEST['table'],
				'column' => field('attname'),
			]
		),
		'icon' => 'Column',
		'iconAction' => url(
			'display.php',
			$reqvars,
			[
				'table' => $_REQUEST['table'],
				'column' => field('attname'),
				'query' => replace(
					'SELECT "%column%", count(*) AS "count" FROM "%table%" GROUP BY "%column%" ORDER BY "%column%"',
					[
						'%column%' => field('attname'),
						'%table%' => $_REQUEST['table'],
					]
				),
			]
		),
		'toolTip' => field('comment'),
	];

	$misc->printTree($columns, $attrs, 'tblcolumns');

	exit;
}

// Main program

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree') {
	doTree();
}

$misc = AppContainer::getMisc();

$misc->printHeader($lang['strtables'] . ' - ' . $_REQUEST['table']);
$misc->printBody();

switch ($action) {
	case 'alter':
		if (isset($_POST['alter'])) {
			doSaveAlter();
		} else {
			doDefault();
		}

		break;
	case 'confirm_alter':
		doAlter();
		break;
	case 'import':
		doImport();
		break;
	case 'export':
		doExport();
		break;
	case 'add_column':
		if (isset($_POST['cancel'])) {
			doDefault();
		} else {
			doAddColumn();
		}

		break;
	case 'properties':
		if (isset($_POST['cancel'])) {
			doDefault();
		} else {
			doProperties();
		}

		break;
	case 'drop':
		if (isset($_POST['drop'])) {
			doDrop(false);
		} else {
			doDefault();
		}

		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
