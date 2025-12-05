<?php
$_no_db_connection = true;
include_once('./libraries/lib.inc.php');
if (true || isset($_SESSION['webdbLogin'])) {
	require 'intro.php';
	//header("Location: intro.php?$misc->href");
} else {
	//header("Location: servers.php");
	require 'servers.php';
}
