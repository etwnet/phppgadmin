<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Manage casts in a database
 *
 * $Id: casts.php,v 1.16 2007/09/25 16:08:05 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Show default list of casts in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);

	$renderCastContext = function ($val) use ($lang) {
		switch ($val) {
			case 'e':
				return $lang['strno'];
			case 'a':
				return $lang['strinassignment'];
			default:
				return $lang['stryes'];
		}
	};

	$misc->printTrail('database');
	$misc->printTabs('database', 'casts');
	$misc->printMsg($msg);

	$casts = $typeActions->getCasts();

	$columns = [
		'source_type' => [
			'title' => $lang['strsourcetype'],
			'field' => field('castsource'),
			'icon' => $misc->icon('Cast'),
		],
		'target_type' => [
			'title' => $lang['strtargettype'],
			'field' => field('casttarget'),
		],
		'function' => [
			'title' => $lang['strfunction'],
			'field' => field('castfunc'),
			'params' => ['null' => $lang['strbinarycompat']],
		],
		'implicit' => [
			'title' => $lang['strimplicit'],
			'field' => field('castcontext'),
			'type' => 'callback',
			'params' => ['function' => $renderCastContext, 'align' => 'center'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('castcomment'),
		],
	];

	$actions = [];

	$misc->printTable($casts, $columns, $actions, 'casts-casts', $lang['strnocasts']);
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$typeActions = new TypeActions($pg);

	$casts = $typeActions->getCasts();

	$proto = concat(field('castsource'), ' AS ', field('casttarget'));

	$attrs = [
		'text' => $proto,
		'icon' => 'Cast'
	];

	$misc->printTree($casts, $attrs, 'casts');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';


if ($action == 'tree')
	doTree();

$misc->printHeader($lang['strcasts']);
$misc->printBody();

switch ($action) {
	case 'tree':
		doTree();
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
