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

	?>
	<form action="tblproperties.php" method="post">
		<table>
			<tr>
				<th class="data left required"><?= $lang['strname'] ?></th>
				<td class="data1">
					<input name="name" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_POST['name'], ENT_QUOTES) ?>" />
				</td>
			</tr>

			<?php if ($roleActions->isSuperUser()): ?>
				<tr>
					<th class="data left required"><?= $lang['strowner'] ?></th>
					<td class="data1"><select name="owner">
							<?php while (!$users->EOF):
								$uname = $users->fields['usename']; ?>
								<option value="<?= html_esc($uname) ?>" <?= ($uname == $_POST['owner']) ? ' selected="selected"' : '' ?>><?= html_esc($uname) ?></option>
								<?php $users->moveNext(); endwhile; ?>
						</select></td>
				</tr>
			<?php endif; ?>

			<?php if ($pg->hasAlterTableSchema()): ?>
				<?php $schemas = $schemaActions->getSchemas(); ?>
				<tr>
					<th class="data left required"><?= $lang['strschema'] ?></th>
					<td class="data1"><select name="newschema">
							<?php while (!$schemas->EOF):
								$schema = $schemas->fields['nspname']; ?>
								<option value="<?= html_esc($schema) ?>" <?= ($schema == $_POST['newschema']) ? ' selected="selected"' : '' ?>><?= html_esc($schema) ?></option>
								<?php $schemas->moveNext(); endwhile; ?>
						</select></td>
				</tr>
			<?php endif; ?>

			<?php if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0): ?>
				<tr>
					<th class="data left"><?= $lang['strtablespace'] ?></th>
					<td class="data1">
						<select name="tablespace">
							<option value="" <?= ($_POST['tablespace'] == '') ? ' selected="selected"' : '' ?>></option>
							<?php while (!$tablespaces->EOF):
								$spcname = html_esc($tablespaces->fields['spcname']); ?>
								<option value="<?= $spcname ?>" <?= ($spcname == $_POST['tablespace']) ? ' selected="selected"' : '' ?>><?= $spcname ?></option>
								<?php $tablespaces->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<tr>
				<th class="data left"><?= $lang['strcomment'] ?></th>
				<td class="data1">
					<textarea rows="3" cols="32" name="comment"><?= html_esc($_POST['comment'] ?? '') ?></textarea>
				</td>
			</tr>
		</table>
		<p><input type="hidden" name="action" value="alter" />
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<?= $misc->form ?>
			<input type="submit" name="alter" value="<?= $lang['stralter'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

function doExport($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printTrail('table');
	$misc->printTabs('table', 'export');
	$misc->printMsg($msg);

	// Use the unified DumpRenderer for the export form
	$dumpRenderer = new DumpRenderer();
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

	?>
	<form action="dataimport.php" method="post" enctype="multipart/form-data">
		<table>
			<tr>
				<th class="data left required"><?= $lang['strformat'] ?></th>
				<td><select name="format">
						<option value="auto"><?= $lang['strauto'] ?></option>
						<option value="csv">CSV</option>
						<option value="tab"><?= $lang['strtabbed'] ?></option>
						<?php if (function_exists('xml_parser_create')): ?>
							<option value="xml">XML</option>
						<?php endif; ?>
					</select></td>
			</tr>
			<tr>
				<th class="data left required"><?= $lang['strallowednulls'] ?></th>
				<td>
					<label><input type="checkbox" name="allowednulls[0]" value="\N"
							checked="checked" /><?= $lang['strbackslashn'] ?></label><br />
					<label><input type="checkbox" name="allowednulls[1]" value="NULL" />NULL</label><br />
					<label><input type="checkbox" name="allowednulls[2]" value="" /><?= $lang['stremptystring'] ?></label>
				</td>
			</tr>
			<tr>
				<th class="data left required"><?= $lang['strfile'] ?></th>
				<td><input type="hidden" name="MAX_FILE_SIZE" value="<?= $max_size ?>" /><input type="file" name="source" />
				</td>
			</tr>
		</table>
		<p><input type="hidden" name="action" value="import" />
			<?= $misc->form ?>
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<input type="submit" value="<?= $lang['strimport'] ?>" />
		</p>
	</form>
	<?php
}

function doAddColumn($msg = '')
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$misc = AppContainer::getMisc();
	$typeActions = new TypeActions($pg);

	// Determine number of rows to display
	if (!isset($_POST['num_columns'])) {
		$numColumns = 10;
	} else {
		$numColumns = (int) $_POST['num_columns'];
	}

	// Initialize arrays for form values
	for ($i = 0; $i < $numColumns; $i++) {
		if (!isset($_POST['field'][$i])) {
			$_POST['field'][$i] = '';
		}
		if (!isset($_POST['type'][$i])) {
			$_POST['type'][$i] = '';
		}
		if (!isset($_POST['array'][$i])) {
			$_POST['array'][$i] = '';
		}
		if (!isset($_POST['length'][$i])) {
			$_POST['length'][$i] = '';
		}
		if (!isset($_POST['default'][$i])) {
			$_POST['default'][$i] = '';
		}
		if (!isset($_POST['comment'][$i])) {
			$_POST['comment'][$i] = '';
		}
	}

	// Fetch all available types
	$types = $typeActions->getTypes(true, false, true);
	$allTypes = $pg->extraTypes;
	while (!$types->EOF) {
		$allTypes[] = $types->fields['typname'];
		$types->moveNext();
	}
	// Exclude types that cannot be used to create columns
	$allTypes = array_diff($allTypes, ColumnActions::EXCLUDE_TYPES);

	$misc->printTrail('table');
	$misc->printTitle($lang['straddcolumn'], 'pg.column.add');
	$misc->printMsg($msg);

	?>
	<script src="js/tables.js" type="text/javascript"></script>
	<form action="tblproperties.php" method="post">
		<table id="columnsTable">
			<tr>
				<th class="data required"><?= $lang['strname'] ?></th>
				<th colspan="2" class="data required"><?= $lang['strtype'] ?></th>
				<th class="data"><?= $lang['strlength'] ?></th>
				<th class="data text-center"><?= $lang['strnotnull'] ?></th>
				<th class="data"><?= $lang['strdefault'] ?></th>
				<th class="data"><?= $lang['strcomment'] ?></th>
			</tr>

			<?php
			$predefined_size_types = array_intersect($pg->predefinedSizeTypes, $allTypes);
			$escaped_predef_types = []; // the JS escaped array elements
			foreach ($predefined_size_types as $value) {
				$escaped_predef_types[] = "'{$value}'";
			}

			for ($i = 0; $i < $numColumns; $i++) {
				?>
				<tr class="data<?= ($i & 1) == 0 ? '1' : '2' ?>" data-row-index="<?= $i ?>">
					<td><input name="field[<?= $i ?>]" size="16" maxlength="<?= $pg->_maxNameLen ?>"
							value="<?= html_esc($_POST['field'][$i]) ?>" /></td>
					<td><select name="type[<?= $i ?>]" id="types<?= $i ?>" onchange="checkLengths(this.value, <?= $i ?>);">
							<?php
							foreach ($allTypes as $type) {
								$sel = ($type == $_POST['type'][$i]) ? ' selected="selected"' : '';
								?>
								<option value="<?= html_esc($type) ?>" <?= $sel ?>><?= $misc->printVal($type) ?></option>
								<?php
							}
							?>
						</select></td>

					<td><select name="array[<?= $i ?>]">
							<option value="" <?= ($_POST['array'][$i] == '') ? ' selected="selected"' : '' ?>></option>
							<option value="[]" <?= ($_POST['array'][$i] == '[]') ? ' selected="selected"' : '' ?>>[ ]</option>
						</select></td>
					<td><input name="length[<?= $i ?>]" id="lengths<?= $i ?>" size="8"
							value="<?= html_esc($_POST['length'][$i]) ?>" /></td>
					<td class="text-center"><input type="checkbox" name="notnull[<?= $i ?>]" <?= (isset($_POST['notnull'][$i])) ? ' checked="checked"' : '' ?> />
					</td>
					<td><input name="default[<?= $i ?>]" size="20" value="<?= html_esc($_POST['default'][$i]) ?>" /></td>
					<td><input name="comment[<?= $i ?>]" size="40" value="<?= html_esc($_POST['comment'][$i]) ?>" /></td>
				</tr>
				<?php
			}
			?>
		</table>
		<div class="flex-row my-3">
			<input type="hidden" name="action" value="save_add_column" />
			<input type="hidden" name="num_columns" id="num_columns" value="<?= $numColumns ?>" />
			<?= $misc->form ?>
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<div>
				<input type="submit" name="add" value="<?= $lang['strsave'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</div>
			<div class="ml-auto">
				<input type="button" value="<?= $lang['straddmorecolumns'] ?>" onclick="addColumnRow();" />
			</div>
		</div>
	</form>
	<script type="text/javascript">
		var predefined_lengths = new Array(<?= implode(",", $escaped_predef_types) ?>);
		var maxNameLen = <?= $pg->_maxNameLen ?>;
		var allTypes = <?= json_encode(array_values($allTypes)) ?>;
		// Initialize all rows
		for (var i = 0; i < <?= $numColumns ?>; i++) {
			checkLengths(document.getElementById('types' + i).value, i);
		}
	</script>
	<?php
}

function doSaveAddColumn()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);

	// Determine number of columns
	$numColumns = isset($_POST['num_columns']) ? (int) $_POST['num_columns'] : 1;

	// Collect valid columns (non-empty field names)
	$validColumns = [];
	for ($i = 0; $i < $numColumns; $i++) {
		if (isset($_POST['field'][$i]) && trim($_POST['field'][$i]) != '') {
			$validColumns[] = [
				'index' => $i,
				'field' => trim($_POST['field'][$i]),
				'type' => isset($_POST['type'][$i]) ? $_POST['type'][$i] : '',
				'array' => isset($_POST['array'][$i]) && $_POST['array'][$i] != '',
				'length' => isset($_POST['length'][$i]) ? $_POST['length'][$i] : '',
				'notnull' => isset($_POST['notnull'][$i]),
				'default' => isset($_POST['default'][$i]) ? $_POST['default'][$i] : '',
				'comment' => isset($_POST['comment'][$i]) ? $_POST['comment'][$i] : ''
			];
		}
	}

	// Check if at least one column is provided
	if (empty($validColumns)) {
		doAddColumn($lang['strcolneedsname'], $_POST);
		return;
	}

	// Begin transaction
	$status = $pg->beginTransaction();
	if ($status != 0) {
		doAddColumn($lang['strcolumnaddedbad'], $_POST);
		return;
	}

	// Add each column
	$addedCount = 0;
	$failedColumn = null;
	$errorMsg = '';

	foreach ($validColumns as $col) {
		$status = $columnActions->addColumn(
			$_POST['table'],
			$col['field'],
			$col['type'],
			$col['array'],
			$col['length'],
			$col['notnull'],
			$col['default'],
			$col['comment']
		);

		if ($status != 0) {
			// Rollback transaction on error
			$pg->rollbackTransaction();
			$failedColumn = $col;
			$errorMsg = sprintf(
				$lang['strcolumnaddedbad'] . ' - Row %d (%s): %s',
				$col['index'] + 1,
				html_esc($col['field']),
				$lang['strcolumnaddedbad']
			);
			// Redisplay form with error
			doAddColumn($errorMsg);
			return;
		}
		$addedCount++;
	}

	// Commit transaction
	$status = $pg->endTransaction();
	if ($status != 0) {
		doAddColumn($lang['strcolumnaddedbad'], $_POST);
		return;
	}

	// Success
	AppContainer::setShouldReloadTree(true);
	if ($addedCount == 1) {
		doDefault($lang['strcolumnadded']);
	} else {
		doDefault(sprintf('%d %s', $addedCount, $lang['strcolumnsadded'] ?? 'columns added'));
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
		?>
		<p><?= sprintf($lang['strconfdropcolumn'], $misc->printVal($_REQUEST['column']), $misc->printVal($_REQUEST['table'])) ?>
		</p>
		<form action="tblproperties.php" method="post">
			<input type="hidden" name="action" value="drop" />
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<input type="hidden" name="column" value="<?= html_esc($_REQUEST['column']) ?>" />
			<?= $misc->form ?>
			<p><input type="checkbox" id="cascade" name="cascade"> <label for="cascade"><?= $lang['strcascade'] ?></label></p>
			<input type="submit" name="drop" value="<?= $lang['strdrop'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</form>
		<?php
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
		?>
		<p class="comment"><?= $misc->printVal($tdata->fields['relcomment']) ?></p>
		<?php
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
	case 'save_add_column':
		if (isset($_POST['add'])) {
			doSaveAddColumn();
		} else {
			doDefault();
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
