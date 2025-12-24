<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * Manage databases within a server
 *
 * $Id: all_db.php,v 1.59 2007/10/17 21:40:19 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Display a form for alter and perform actual alter
 */
function doAlter($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);
	$databaseActions = new DatabaseActions($pg);

	if ($confirm) {
		$dbName = $_REQUEST['alterdatabase'] ?? '';
		$misc->printTrail('database');
		$misc->printTitle("{$lang['stralterdatabase']}: $dbName", 'pg.database.alter');

		?>
		<form action="all_db.php" method="post">
			<table>
				<tr>
					<th class="data left required"><?= $lang['strname']; ?></th>
					<td class="data1">
						<input name="newname" size="32" maxlength="<?= $pg->_maxNameLen; ?>"
							value="<?= htmlspecialchars_nc($dbName); ?>" />
					</td>
				</tr>

				<?php if ($roleActions->isSuperUser()): ?>
					<?php
					// Fetch all users
					$rs = $databaseActions->getDatabaseOwner($dbName);
					$owner = $rs->fields['usename'] ?? '';
					$users = $roleActions->getUsers();
					?>
					<tr>
						<th class="data left required"><?= $lang['strowner']; ?></th>
						<td class="data1">
							<select name="owner">
								<?php
								while (!$users->EOF) {
									$uname = $users->fields['usename'];
									?>
									<option value="<?= htmlspecialchars_nc($uname); ?>" <?php if ($uname == $owner)
										  echo ' selected="selected"'; ?>>
										<?= htmlspecialchars_nc($uname); ?>
									</option>
									<?php
									$users->moveNext();
								}
								?>
							</select>
						</td>
					</tr>
				<?php endif; ?>

				<?php if ($pg->hasSharedComments()): ?>
					<?php
					$rs = $databaseActions->getDatabaseComment($dbName);
					$comment = $rs->fields['description'] ?? '';
					?>
					<tr>
						<th class="data left"><?= $lang['strcomment']; ?></th>
						<td class="data1">
							<textarea rows="3" cols="32" name="dbcomment"><?= htmlspecialchars_nc($comment); ?></textarea>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<input type="hidden" name="action" value="alter" />
			<?= $misc->form; ?>
			<input type="hidden" name="oldname" value="<?= htmlspecialchars_nc($dbName); ?>" />
			<p>
				<input type="submit" name="alter" value="<?= $lang['stralter']; ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
			</p>
		</form>
		<?php
	} else {
		if (!isset($_POST['owner']))
			$_POST['owner'] = '';
		if (!isset($_POST['dbcomment']))
			$_POST['dbcomment'] = '';
		$status = $databaseActions->alterDatabase(
			$_POST['oldname'],
			$_POST['newname'],
			$_POST['owner'],
			$_POST['dbcomment']
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strdatabasealtered']);
		} else
			doDefault($lang['strdatabasealteredbad']);
	}
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);

	if (empty($_REQUEST['dropdatabase']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifydatabasetodrop']);
		exit();
	}

	if ($confirm) {

		$misc->printTrail('database');
		$misc->printTitle($lang['strdrop'], 'pg.database.drop');

		?>
		<form action="all_db.php" method="post">
			<?php
			// If multi drop
			if (isset($_REQUEST['ma'])) {
				foreach ($_REQUEST['ma'] as $v) {
					$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
					?>
					<p><?= sprintf($lang['strconfdropdatabase'], $misc->printVal($a['database'])); ?></p>
					<input type="hidden" name="dropdatabase[]" value="<?= htmlspecialchars_nc($a['database']); ?>" />
					<?php
				}
			} else {
				?>
				<p><?= sprintf($lang['strconfdropdatabase'], $misc->printVal($_REQUEST['dropdatabase'])); ?></p>
				<input type="hidden" name="dropdatabase" value="<?= htmlspecialchars_nc($_REQUEST['dropdatabase']); ?>" />
				<?php
			}
			?>
			<input type="hidden" name="action" value="drop" />
			<?= $misc->form; ?>
			<input type="submit" name="drop" value="<?= $lang['strdrop']; ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
		</form>
		<?php
	} // END confirm
	else {
		//If multi drop
		if (is_array($_REQUEST['dropdatabase'])) {
			$msg = '';
			foreach ($_REQUEST['dropdatabase'] as $d) {
				$status = $databaseActions->dropDatabase($d);
				if ($status == 0)
					$msg .= sprintf('%s: %s<br />', htmlentities($d, ENT_QUOTES, 'UTF-8'), $lang['strdatabasedropped']);
				else {
					doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($d, ENT_QUOTES, 'UTF-8'), $lang['strdatabasedroppedbad']));
					return;
				}
			} // Everything went fine, back to Default page...
			AppContainer::setShouldReloadTree(true);
			doDefault($msg);
		} else {
			$status = $databaseActions->dropDatabase($_POST['dropdatabase']);
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strdatabasedropped']);
			} else
				doDefault($lang['strdatabasedroppedbad']);
		}
	} //END DROP
}// END FUNCTION


/**
 * Displays a screen where they can enter a new database
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);
	$tablespaceActions = new TablespaceActions($pg);

	$misc->printTrail('server');
	$misc->printTitle($lang['strcreatedatabase'], 'pg.database.create');
	$misc->printMsg($msg);

	if (!isset($_POST['formName']))
		$_POST['formName'] = '';
	// Default encoding is that in language file
	if (!isset($_POST['formEncoding'])) {
		$_POST['formEncoding'] = '';
	}
	if (!isset($_POST['formTemplate']))
		$_POST['formTemplate'] = 'template1';
	if (!isset($_POST['formSpc']))
		$_POST['formSpc'] = '';
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = '';

	// Fetch a list of databases in the cluster
	$templatedbs = $databaseActions->getDatabases(false);

	// Fetch all tablespaces from the database
	if ($pg->hasTablespaces())
		$tablespaces = $tablespaceActions->getTablespaces();
	?>
	<form action="all_db.php" method="post">
		<table>
			<tr>
				<th class="data left required"><?= $lang['strname']; ?></th>
				<td class="data1">
					<input name="formName" size="32" maxlength="<?= $pg->_maxNameLen; ?>"
						value="<?= htmlspecialchars_nc($_POST['formName']); ?>" />
				</td>
			</tr>

			<tr>
				<th class="data left required"><?= $lang['strtemplatedb']; ?></th>
				<td class="data1">
					<select name="formTemplate">
						<!-- Always offer template0 and template1 -->
						<option value="template0" <?php if ($_POST['formTemplate'] == 'template0')
							echo ' selected="selected"'; ?>>template0</option>
						<option value="template1" <?php if ($_POST['formTemplate'] == 'template1')
							echo ' selected="selected"'; ?>>template1</option>
						<?php
						while (!$templatedbs->EOF) {
							$dbname = htmlspecialchars_nc($templatedbs->fields['datname']);
							if ($dbname != 'template1') {
								// filter out for $conf[show_system] users so we don't get duplicates
								?>
								<option value="<?= $dbname; ?>" <?php if ($dbname == $_POST['formTemplate'])
									  echo ' selected="selected"'; ?>><?= $dbname; ?></option>
								<?php
							}
							$templatedbs->moveNext();
						}
						?>
					</select>
				</td>
			</tr>

			<!-- ENCODING -->
			<tr>
				<th class="data left required"><?= $lang['strencoding']; ?></th>
				<td class="data1">
					<select name="formEncoding">
						<option value=""></option>
						<?php
						foreach ($pg->codemap as $key) {
							?>
							<option value="<?= htmlspecialchars_nc($key); ?>" <?php if ($key == $_POST['formEncoding'])
								  echo ' selected="selected"'; ?>>
								<?= $misc->printVal($key); ?>
							</option>
							<?php
						}
						?>
					</select>
				</td>
			</tr>

			<?php if ($pg->hasDatabaseCollation()): ?>
				<?php
				if (!isset($_POST['formCollate']))
					$_POST['formCollate'] = '';
				if (!isset($_POST['formCType']))
					$_POST['formCType'] = '';
				?>
				<!-- LC_COLLATE -->
				<tr>
					<th class="data left"><?= $lang['strcollation']; ?></th>
					<td class="data1">
						<input name="formCollate" value="<?= htmlspecialchars_nc($_POST['formCollate']); ?>" />
					</td>
				</tr>

				<!-- LC_CTYPE -->
				<tr>
					<th class="data left"><?= $lang['strctype']; ?></th>
					<td class="data1">
						<input name="formCType" value="<?= htmlspecialchars_nc($_POST['formCType']); ?>" />
					</td>
				</tr>
			<?php endif; ?>

			<!-- Tablespace (if there are any) -->
			<?php if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0): ?>
				<tr>
					<th class="data left"><?= $lang['strtablespace']; ?></th>
					<td class="data1">
						<select name="formSpc">
							<!-- Always offer the default (empty) option -->
							<option value="" <?php if ($_POST['formSpc'] == '')
								echo ' selected="selected"'; ?>></option>
							<?php
							// Display all other tablespaces
							while (!$tablespaces->EOF) {
								$spcname = htmlspecialchars_nc($tablespaces->fields['spcname']);
								?>
								<option value="<?= $spcname; ?>" <?php if ($spcname == $_POST['formSpc'])
									  echo ' selected="selected"'; ?>>
									<?= $spcname; ?>
								</option>
								<?php
								$tablespaces->moveNext();
							}
							?>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<!-- Comments (if available) -->
			<?php if ($pg->hasSharedComments()): ?>
				<tr>
					<th class="data left"><?= $lang['strcomment']; ?></th>
					<td>
						<textarea name="formComment" rows="3"
							cols="32"><?= htmlspecialchars_nc($_POST['formComment']); ?></textarea>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<p>
			<input type="hidden" name="action" value="save_create" />
			<?= $misc->form; ?>
			<input type="submit" value="<?= $lang['strcreate']; ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
		</p>
	</form>
	<?php
}

/**
 * Actually creates the new view in the database
 */
function doSaveCreate()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);

	// Default tablespace to null if it isn't set
	if (!isset($_POST['formSpc']))
		$_POST['formSpc'] = null;

	// Default comment to blank if it isn't set
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = null;

	// Default collate to blank if it isn't set
	if (!isset($_POST['formCollate']))
		$_POST['formCollate'] = null;

	// Default ctype to blank if it isn't set
	if (!isset($_POST['formCType']))
		$_POST['formCType'] = null;

	// Check that they've given a name and a definition
	if ($_POST['formName'] == '')
		doCreate($lang['strdatabaseneedsname']);
	else {
		$status = $databaseActions->createDatabase(
			$_POST['formName'],
			$_POST['formEncoding'],
			$_POST['formSpc'],
			$_POST['formComment'],
			$_POST['formTemplate'],
			$_POST['formCollate'],
			$_POST['formCType']
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strdatabasecreated']);
		} else
			doCreate($lang['strdatabasecreatedbad']);
	}
}

/**
 * Displays options for cluster download
 */
function doExport($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);
	$roleActions = new RoleActions($pg);

	$misc->printTrail('server');
	$misc->printTabs('server', 'export');
	$misc->printMsg($msg);

	// Get list of databases
	$databases = $databaseActions->getDatabases();
	$isSuperUser = $roleActions->isSuperUser();
	$accessibleDatabases = [];

	// For non-superusers, check which databases they can connect to
	if (!$isSuperUser) {
		$serverInfo = $misc->getServerInfo();
		while ($databases && !$databases->EOF) {
			$dbName = $databases->fields['datname'];
			// Try to connect to database to verify access
			try {
				$testConn = $misc->getDatabaseAccessor($dbName);
				if ($testConn) {
					$accessibleDatabases[] = $dbName;
				}
			} catch (Exception $e) {
				// User cannot access this database
			}
			$databases->moveNext();
		}
		// Reset to beginning
		$databases->moveFirst();
	} else {
		// Superuser can see all databases
		while ($databases && !$databases->EOF) {
			$accessibleDatabases[] = $databases->fields['datname'];
			$databases->moveNext();
		}
		$databases->moveFirst();
	}

	?>
	<form action="dbexport.php" id="export-form" method="get">
		<!-- Export Type Selection -->
		<fieldset>
			<legend>Export Type</legend>
			<div>
				<input type="radio" id="what_both" name="what" value="structureanddata" checked="checked" />
				<label for="what_both">Structure and Data</label>
			</div>
			<div>
				<input type="radio" id="what_struct" name="what" value="structureonly" />
				<label for="what_struct">Structure Only</label>
			</div>
			<div>
				<input type="radio" id="what_data" name="what" value="dataonly" />
				<label for="what_data">Data Only</label>
			</div>
		</fieldset>

		<!-- Cluster-Level Objects (Server Export Only) -->
		<fieldset>
			<legend>Cluster-Level Objects</legend>
			<div>
				<input type="checkbox" id="export_roles" name="export_roles" value="true" checked="checked" />
				<label for="export_roles">Export Roles/Users</label>
			</div>
			<div>
				<input type="checkbox" id="export_tablespaces" name="export_tablespaces" value="true" checked="checked" />
				<label for="export_tablespaces">Export Tablespaces</label>
			</div>
		</fieldset>

		<!-- Database Selection -->
		<fieldset>
			<legend>Select Databases to Export</legend>
			<p class="small">Uncheck template databases to exclude them</p>
			<?php if (!empty($accessibleDatabases)): ?>
				<?php
				$databases->moveFirst();
				while ($databases && !$databases->EOF) {
					$dbName = $databases->fields['datname'];
					if (in_array($dbName, $accessibleDatabases)) {
						// Check by default unless it's a template database
						$checked = (strpos($dbName, 'template') !== 0) ? 'checked="checked"' : '';
						?>
						<div>
							<input type="checkbox" id="db_<?= htmlspecialchars_nc($dbName); ?>" name="databases[]"
								value="<?= htmlspecialchars_nc($dbName); ?>" <?= $checked; ?> />
							<label for="db_<?= htmlspecialchars_nc($dbName); ?>"><?= htmlspecialchars_nc($dbName); ?></label>
						</div>
						<?php
					}
					$databases->moveNext();
				}
				?>
			<?php else: ?>
				<p>No accessible databases found.</p>
			<?php endif; ?>
		</fieldset>

		<!-- Structure Export Options -->
		<fieldset id="structure_options">
			<legend>Structure Options</legend>
			<div>
				<input type="checkbox" id="drop_objects" name="drop_objects" value="true" />
				<label for="drop_objects">Add DROP statements (DROP TABLE IF EXISTS, etc.)</label>
			</div>
			<div>
				<input type="checkbox" id="if_not_exists" name="if_not_exists" value="true" checked="checked" />
				<label for="if_not_exists">Use IF NOT EXISTS (for safer re-imports)</label>
			</div>
			<div>
				<input type="checkbox" id="include_comments" name="include_comments" value="true" checked="checked" />
				<label for="include_comments">Include object comments</label>
			</div>
			<div>
				<input type="checkbox" id="use_pgdump" name="use_pgdump" value="true" />
				<label for="use_pgdump">Use external pg_dump/pg_dumpall (if available)</label>
			</div>
		</fieldset>

		<!-- Data Export Options (shown only for data-inclusive exports) -->
		<fieldset id="data_options" style="display:none;">
			<legend>Data Export Options</legend>
			<div>
				<p class="small">INSERT Format (COPY is fastest for restore):</p>
				<div>
					<input type="radio" id="insert_copy" name="insert_format" value="copy" checked="checked" />
					<label for="insert_copy">COPY format (fastest)</label>
				</div>
				<div>
					<input type="radio" id="insert_multi" name="insert_format" value="multi" />
					<label for="insert_multi">Multi-row inserts</label>
				</div>
				<div>
					<input type="radio" id="insert_single" name="insert_format" value="single" />
					<label for="insert_single">Single-row inserts</label>
				</div>
			</div>
			<div>
				<input type="checkbox" id="truncate_tables" name="truncate_tables" value="true" />
				<label for="truncate_tables">TRUNCATE tables before insert</label>
			</div>
		</fieldset>

		<!-- Output Options -->
		<fieldset>
			<legend>Output</legend>
			<div>
				<input type="radio" id="output_show" name="output" value="show" checked="checked" />
				<label for="output_show">Show in browser</label>
			</div>
			<div>
				<input type="radio" id="output_download" name="output" value="download" />
				<label for="output_download">Download as file</label>
			</div>
		</fieldset>

		<p>
			<input type="hidden" name="action" value="export" />
			<input type="hidden" name="subject" value="server" />
			<?= $misc->form; ?>
			<input type="submit" value="<?= $lang['strexport']; ?>" />
		</p>
	</form>

	<script>
		{
			// Show/hide options based on export type
			const form = document.getElementById('export-form');
			const whatRadios = form.querySelectorAll('input[name="what"]');
			const structureOptions = document.getElementById('structure_options');
			const dataOptions = document.getElementById('data_options');

			// Only setup if form elements exist on this page
			if (whatRadios.length > 0 && structureOptions && dataOptions) {
				function updateOptions() {
					const selectedWhat = form.querySelector('input[name="what"]:checked').value;

					// Show/hide structure options based on export type
					if (selectedWhat === 'dataonly') {
						structureOptions.style.display = 'none';
					} else {
						structureOptions.style.display = 'block';
					}

					// Show/hide data options based on export type
					if (selectedWhat === 'dataonly' || selectedWhat === 'structureanddata') {
						dataOptions.style.display = 'block';
					} else {
						dataOptions.style.display = 'none';
					}
				}

				whatRadios.forEach(radio => radio.addEventListener('change', updateOptions));
				updateOptions(); // Initial state
			}
		}
	</script>
	<?php
}

/**
 * Show default list of databases in the server
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);

	$misc->printTrail('server');
	$misc->printTabs('server', 'databases');
	$misc->printMsg($msg);

	$databases = $databaseActions->getDatabases();

	$columns = [
		'database' => [
			'title' => $lang['strdatabase'],
			'field' => field('datname'),
			'url' => "redirect.php?subject=database&amp;{$misc->href}&amp;",
			'vars' => ['database' => 'datname'],
			'icon' => $misc->icon('Database'),
			'class' => 'no-wrap',
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('datowner'),
		],
		'encoding' => [
			'title' => $lang['strencoding'],
			'field' => field('datencoding'),
		],
		'lc_collate' => [
			'title' => $lang['strcollation'],
			'field' => field('datcollate'),
		],
		'lc_ctype' => [
			'title' => $lang['strctype'],
			'field' => field('datctype'),
		],
		'tablespace' => [
			'title' => $lang['strtablespace'],
			'field' => field('tablespace'),
		],
		'dbsize' => [
			'title' => $lang['strsize'],
			'field' => field('dbsize'),
			'type' => 'prettysize',
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('datcomment'),
		],
	];

	$actions = [
		'multiactions' => [
			'keycols' => ['database' => 'datname'],
			'url' => 'all_db.php',
			'default' => null,
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'all_db.php',
					'urlvars' => [
						'subject' => 'database',
						'action' => 'confirm_drop',
						'dropdatabase' => field('datname')
					]
				]
			],
			'multiaction' => 'confirm_drop',
		],
		'privileges' => [
			'icon' => $misc->icon('Privileges'),
			'content' => $lang['strprivileges'],
			'attr' => [
				'href' => [
					'url' => 'privileges.php',
					'urlvars' => [
						'subject' => 'database',
						'database' => field('datname')
					]
				]
			]
		]
	];
	if ($pg->hasAlterDatabase()) {
		$actions['alter'] = [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'all_db.php',
					'urlvars' => [
						'subject' => 'database',
						'action' => 'confirm_alter',
						'alterdatabase' => field('datname')
					]
				]
			]
		];
	}

	if (!$pg->hasTablespaces())
		unset($columns['tablespace']);
	if (!$pg->hasServerAdminFuncs())
		unset($columns['dbsize']);
	if (!$pg->hasDatabaseCollation())
		unset($columns['lc_collate'], $columns['lc_ctype']);
	if (!isset($pg->privlist['database']))
		unset($actions['privileges']);

	$misc->printTable($databases, $columns, $actions, 'all_db-databases', $lang['strnodatabases']);

	$navlinks = [
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'all_db.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server']
					]
				]
			],
			'icon' => $misc->icon('CreateDatabase'),
			'content' => $lang['strcreatedatabase']
		]
	];
	$misc->printNavLinks($navlinks, 'all_db-databases', get_defined_vars());
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$databaseActions = new DatabaseActions($pg);

	$databases = $databaseActions->getDatabases();

	$reqvars = $misc->getRequestVars('database');

	$attrs = [
		'text' => field('datname'),
		'icon' => 'Database',
		'toolTip' => field('datcomment'),
		'action' => url(
			'redirect.php',
			$reqvars,
			['database' => field('datname')]
		),
		'branch' => url(
			'database.php',
			$reqvars,
			[
				'action' => 'tree',
				'database' => field('datname')
			]
		),
	];

	$misc->printTree($databases, $attrs, 'databases');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

$misc->printHeader($lang['strdatabases']);
$misc->printBody();

switch ($action) {
	case 'export':
		doExport();
		break;
	case 'save_create':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doSaveCreate();
		break;
	case 'create':
		doCreate();
		break;
	case 'drop':
		if (isset($_REQUEST['drop']))
			doDrop(false);
		else
			doDefault();
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'alter':
		if (isset($_POST['oldname']) && isset($_POST['newname']) && !isset($_POST['cancel']))
			doAlter(false);
		else
			doDefault();
		break;
	case 'confirm_alter':
		doAlter(true);
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
