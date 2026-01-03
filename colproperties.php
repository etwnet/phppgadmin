<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ColumnActions;

/**
 * List Columns properties in tables
 *
 * $Id: colproperties.php
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Displays a screen where they can alter a column
 */
function doAlter($msg = '')
{

	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$typeActions = new TypeActions($pg);
	$columnActions = new ColumnActions($pg);


	if (!isset($_REQUEST['stage']))
		$_REQUEST['stage'] = 1;

	switch ($_REQUEST['stage']) {
		case 1:
			$misc->printTrail('column');
			$misc->printTitle($lang['stralter'], 'pg.column.alter');
			$misc->printMsg($msg);

			?>
			<script src="js/tables.js" type="text/javascript"></script>
			<form action="colproperties.php" method="post">

				<table>
					<tr>
						<th class="data required"><?= $lang['strname'] ?></th>
						<?php if ($pg->hasAlterColumnType()): ?>
							<th class="data required" colspan="2"><?= $lang['strtype'] ?></th>
							<th class="data"><?= $lang['strlength'] ?></th>
						<?php else: ?>
							<th class="data required"><?= $lang['strtype'] ?></th>
						<?php endif; ?>
						<th class="data"><?= $lang['strnotnull'] ?></th>
						<th class="data"><?= $lang['strdefault'] ?></th>
						<th class="data"><?= $lang['strcomment'] ?></th>
					</tr>
					<?php

					$column = $tableActions->getTableAttributes($_REQUEST['table'], $_REQUEST['column']);
					$column->fields['attnotnull'] = $pg->phpBool($column->fields['attnotnull']);

					// Upon first drawing the screen, load the existing column information
					// from the database.
					if (!isset($_REQUEST['default'])) {
						$_REQUEST['field'] = $column->fields['attname'];
						$_REQUEST['type'] = $column->fields['base_type'];
						// Check to see if its' an array type...
						// XXX: HACKY
						if (substr($column->fields['base_type'], strlen($column->fields['base_type']) - 2) == '[]') {
							$_REQUEST['type'] = substr($column->fields['base_type'], 0, strlen($column->fields['base_type']) - 2);
							$_REQUEST['array'] = '[]';
						} else {
							$_REQUEST['type'] = $column->fields['base_type'];
							$_REQUEST['array'] = '';
						}
						// To figure out the length, look in the brackets :(
						// XXX: HACKY
						if ($column->fields['type'] != $column->fields['base_type'] && preg_match('/\\(([0-9, ]*)\\)/', $column->fields['type'], $bits)) {
							$_REQUEST['length'] = $bits[1];
						} else
							$_REQUEST['length'] = '';
						$_REQUEST['default'] = $_REQUEST['olddefault'] = $column->fields['adsrc'];
						if ($column->fields['attnotnull'])
							$_REQUEST['notnull'] = 'YES';
						$_REQUEST['comment'] = $column->fields['comment'];
					}

					// Column name
					?>
					<tr>
						<td><input name="field" size="16" maxlength="<?= $pg->_maxNameLen ?>"
								value="<?= html_esc($_REQUEST['field']) ?>" /></td>
						<?php

						// Column type
						$escaped_predef_types = []; // the JS escaped array elements
						if ($pg->hasAlterColumnType()) {
							// Fetch all available types
							$types = $typeActions->getTypes(true, false, true);
							$types_for_js = [];

							?>
							<td><select name="type" id="type" onchange="checkLengths(document.getElementById('type').value,'');">
									<?php while (!$types->EOF):
										$typname = $types->fields['typname'];
										$types_for_js[] = $typname; ?>
										<option value="<?= html_esc($typname) ?>" <?= ($typname == $_REQUEST['type']) ? ' selected="selected"' : '' ?>><?= $misc->printVal($typname) ?></option>
										<?php
										$types->moveNext();
									endwhile; ?>
								</select></td>
							<?php

							// Output array type selector
							?>
							<td><select name="array">
									<option value="" <?= ($_REQUEST['array'] == '') ? ' selected="selected"' : '' ?>></option>
									<option value="[]" <?= ($_REQUEST['array'] == '[]') ? ' selected="selected"' : '' ?>>[ ]</option>
								</select></td>
							<?php
							$predefined_size_types = array_intersect($pg->predefinedSizeTypes, $types_for_js);
							foreach ($predefined_size_types as $value) {
								$escaped_predef_types[] = "'{$value}'";
							}
							?>
							<td><input name="length" id="lengths" size="8" value="<?= html_esc($_REQUEST['length']) ?>" /></td>
							<?php
						} else {
							// Otherwise draw the read-only type name
							?>
							<td><?= $misc->printVal($pg->formatType($column->fields['type'], $column->fields['atttypmod'])) ?></td>
							<?php
						}

						?>
						<td><input type="checkbox" name="notnull" <?= (isset($_REQUEST['notnull'])) ? ' checked="checked"' : '' ?> />
						</td>
						<td><input name="default" size="20" value="<?= html_esc($_REQUEST['default']) ?>" /></td>
						<td><input name="comment" size="40" value="<?= html_esc($_REQUEST['comment']) ?>" /></td>
					</tr>
				</table>
				<p><input type="hidden" name="action" value="properties" />
					<input type="hidden" name="stage" value="2" />
					<?= $misc->form ?>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
					<input type="hidden" name="column" value="<?= html_esc($_REQUEST['column']) ?>" />
					<input type="hidden" name="olddefault" value="<?= html_esc($_REQUEST['olddefault']) ?>" />
					<?php if ($column->fields['attnotnull']): ?>
						<input type="hidden" name="oldnotnull" value="on" />
					<?php endif; ?>
					<input type="hidden" name="oldtype"
						value="<?= html_esc($pg->formatType($column->fields['type'], $column->fields['atttypmod'])) ?>" />
					<?php
					// Add hidden variables to suppress error notices if we don't support altering column type
					if (!$pg->hasAlterColumnType()): ?>
						<input type="hidden" name="type" value="<?= html_esc($_REQUEST['type']) ?>" />
						<input type="hidden" name="length" value="<?= html_esc($_REQUEST['length']) ?>" />
						<input type="hidden" name="array" value="<?= html_esc($_REQUEST['array']) ?>" />
					<?php endif; ?>
					<input type="submit" value="<?= $lang['stralter'] ?>" />
					<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
				</p>
			</form>
			<script
				type="text/javascript">predefined_lengths = new Array(<?= implode(",", $escaped_predef_types) ?>); checkLengths(document.getElementById('type').value, '');</script>
			<?php
			break;
		case 2:
			// Check inputs
			if (trim($_REQUEST['field']) == '') {
				$_REQUEST['stage'] = 1;
				doAlter($lang['strcolneedsname']);
				return;
			}
			if (!isset($_REQUEST['length']))
				$_REQUEST['length'] = '';
			$status = $columnActions->alterColumn(
				$_REQUEST['table'],
				$_REQUEST['column'],
				$_REQUEST['field'],
				isset($_REQUEST['notnull']),
				isset($_REQUEST['oldnotnull']),
				$_REQUEST['default'],
				$_REQUEST['olddefault'],
				$_REQUEST['type'],
				$_REQUEST['length'],
				$_REQUEST['array'],
				$_REQUEST['oldtype'],
				$_REQUEST['comment']
			);
			if ($status == 0) {
				if ($_REQUEST['column'] != $_REQUEST['field']) {
					$_REQUEST['column'] = $_REQUEST['field'];
					AppContainer::setShouldReloadTree(true);
				}
				doDefault($lang['strcolumnaltered']);
			} else {
				$_REQUEST['stage'] = 1;
				doAlter($lang['strcolumnalteredbad']);
				return;
			}
			break;
		default:
			?>
			<p><?= $lang['strinvalidparam'] ?></p>
		<?php
	}
}

/**
 * Show default list of columns in the table
 */
function doDefault($msg = '', $isTable = true)
{
	global $tableName;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	$attPre = function (&$rowdata) use ($pg) {
		$rowdata->fields['+type'] = $pg->formatType($rowdata->fields['type'], $rowdata->fields['atttypmod']);
	};

	if (empty($_REQUEST['column']))
		$msg .= "<br/>{$lang['strnoobjects']}";

	$misc->printTrail('column');
	//$misc->printTitle($lang['strcolprop']);
	$misc->printTabs('column', 'properties');
	$misc->printMsg($msg);

	if (!empty($_REQUEST['column'])) {
		// Get table
		$tdata = $tableActions->getTable($tableName);
		// Get columns
		$attrs = $tableActions->getTableAttributes($tableName, $_REQUEST['column']);

		// Show comment if any
		if ($attrs->fields['comment'] !== null):
			?>
			<p class="comment"><?= $misc->printVal($attrs->fields['comment']) ?></p>
			<?php
		endif;

		$column = [
			'column' => [
				'title' => $lang['strcolumn'],
				'field' => field('attname'),
			],
			'type' => [
				'title' => $lang['strtype'],
				'field' => field('+type'),
			]
		];

		if ($isTable) {
			$column['notnull'] = [
				'title' => $lang['strnotnull'],
				'field' => field('attnotnull'),
				'type' => 'bool',
				'params' => ['true' => 'NOT NULL', 'false' => '']
			];
			$column['default'] = [
				'title' => $lang['strdefault'],
				'field' => field('adsrc'),
			];
		}

		$actions = [];
		$misc->printTable($attrs, $column, $actions, 'colproperties-colproperties', null, $attPre);

		?>
		<br />
		<?php

		$f_attname = $_REQUEST['column'];
		$f_table = $tableName;
		$f_schema = $pg->_schema;
		$pg->fieldClean($f_attname);
		$pg->fieldClean($f_table);
		$pg->fieldClean($f_schema);
		$query = "SELECT \"{$f_attname}\", count(*) AS \"count\" FROM \"{$f_schema}\".\"{$f_table}\" GROUP BY \"{$f_attname}\" ORDER BY \"{$f_attname}\"";

		if ($isTable) {

			/* Browse link */
			/* FIXME browsing a col should somehow be a action so we don't
			 * send an ugly SQL in the URL */

			$navlinks = [
				'browse' => [
					'attr' => [
						'href' => [
							'url' => 'display.php',
							'urlvars' => [
								'subject' => 'column',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'table' => $tableName,
								'column' => $_REQUEST['column'],
								'return' => 'column',
								'query' => $query
							]
						]
					],
					'icon' => $misc->icon('browsedata'),
					'content' => $lang['strbrowse'],
				],
				'alter' => [
					'attr' => [
						'href' => [
							'url' => 'colproperties.php',
							'urlvars' => [
								'action' => 'properties',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'table' => $tableName,
								'column' => $_REQUEST['column'],
							]
						]
					],
					'content' => $lang['stralter'],
				],
				'drop' => [
					'attr' => [
						'href' => [
							'url' => 'tblproperties.php',
							'urlvars' => [
								'action' => 'confirm_drop',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'table' => $tableName,
								'column' => $_REQUEST['column'],
							]
						]
					],
					'content' => $lang['strdrop'],
				]
			];
		} else {
			/* Browse link */
			$navlinks = [
				'browse' => [
					'attr' => [
						'href' => [
							'url' => 'display.php',
							'urlvars' => [
								'subject' => 'column',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'view' => $tableName,
								'column' => $_REQUEST['column'],
								'return' => 'column',
								'query' => $query
							]
						]
					],
					'content' => $lang['strbrowse']
				]
			];
		}

		$misc->printNavLinks($navlinks, 'colproperties-colproperties', get_defined_vars());
	}
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';
if (isset($_REQUEST['table']))
	$tableName = &$_REQUEST['table'];
elseif (isset($_REQUEST['view']))
	$tableName = &$_REQUEST['view'];
else
	die($lang['strnotableprovided']);


$misc->printHeader($lang['strtables'] . ' - ' . $tableName);
$misc->printBody();

if (isset($_REQUEST['view']))
	doDefault(null, false);
else
	switch ($action) {
		case 'properties':
			if (isset($_POST['cancel']))
				doDefault();
			else
				doAlter();
			break;
		default:
			doDefault();
			break;
	}

$misc->printFooter();
