<?php

use PhpPgAdmin\Core\AppContainer;

	/**
	 * Manage opclasss in a database
	 *
	 * $Id: opclasses.php,v 1.10 2007/08/31 18:30:11 ioguix Exp $
	 */

	// Include application functions
	include_once('./libraries/bootstrap.php');
	
	$action = $_REQUEST['action'] ?? '';
	if (!isset($msg)) $msg = '';

	/**
	 * Show default list of opclasss in the database
	 */
	function doDefault($msg = '') {
		$data = AppContainer::getData();
$conf = AppContainer::getConf();
$misc = AppContainer::getMisc();
		$lang = AppContainer::getLang();
		
		$misc->printTrail('schema');
		$misc->printTabs('schema','opclasses');
		$misc->printMsg($msg);
		
		$opclasses = $data->getOpClasses();
		
		$columns = array(
			'accessmethod' => array(
				'title' => $lang['straccessmethod'],
				'field' => field('amname'),
			),
			'opclass' => array(
				'title' => $lang['strname'],
				'field' => field('opcname'),
			),
			'type' => array(
				'title' => $lang['strtype'],
				'field' => field('opcintype'),
			),
			'default' => array(
				'title' => $lang['strdefault'],
				'field' => field('opcdefault'),
				'type'  => 'yesno',
			),
			'comment' => array(
				'title' => $lang['strcomment'],
				'field' => field('opccomment'),
			),
		);
		
		$actions = array();
		
		$misc->printTable($opclasses, $columns, $actions, 'opclasses-opclasses', $lang['strnoopclasses']);
	}
	
	/**
	 * Generate XML for the browser tree.
	 */
	function doTree() {
		$misc = AppContainer::getMisc();
$data = AppContainer::getData();
		
		$opclasses = $data->getOpClasses();
		
		// OpClass prototype: "op_class/access_method"
		$proto = concat(field('opcname'),'/',field('amname'));
		
		$attrs = array(
			'text'   => $proto,
			'icon'   => 'OperatorClass',
			'toolTip'=> field('opccomment'),
		);
		
		$misc->printTree($opclasses, $attrs, 'opclasses');
		exit;
	}
	
	if ($action == 'tree') doTree();
	
	$misc->printHeader($lang['stropclasses']);
	$misc->printBody();

	switch ($action) {
		default:
			doDefault();
			break;
	}	

	$misc->printFooter();


