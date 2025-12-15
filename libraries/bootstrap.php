<?php

/**
 * Function library read in upon startup
 *
 * $Id: bootstrap.php,v 1.123 2008/04/06 01:10:35 xzilla Exp $
 */

require_once __DIR__ . '/decorator.php';
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../lang/translations.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Misc;
use PhpPgAdmin\PluginManager;

// Set error reporting level to max
error_reporting(E_ALL);

// Application name
AppContainer::setAppName($appName = 'phpPgAdmin');

// Application version
AppContainer::setAppVersion($appVersion = '8.0.rc1');

// PostgreSQL minimum version
AppContainer::setPgServerMinVersion($postgresqlMinVer = '9.0');

/*
// Check the version of PHP
if (version_compare(phpversion(), $phpMinVer, '<'))
	exit(sprintf('Version of PHP not supported. Please upgrade to version %s or later.', $phpMinVer));
*/

// Check to see if the configuration file exists, if not, explain
$configFile = __DIR__ . '/../conf/config.inc.php';
if (file_exists($configFile)) {
	$conf = array();
	require $configFile;
	if (empty($conf['theme'])) {
		$conf['theme'] = 'bootstrap';
	}
	// Set conf by reference
	AppContainer::setConf($conf);
} else {
	echo 'Configuration error: Copy conf/config.inc.php-dist to conf/config.inc.php and edit appropriately.';
	exit;
}

// Session storage configuration
if (!empty($conf['session_path'])) {

	$sessionPath = $conf['session_path'];

	$isRelative = ($sessionPath[0] != '/' && $sessionPath[0] != '\\') &&
		(strlen($sessionPath) <= 2 ||
			($sessionPath[1] != ':' && !($sessionPath[0] == '\\' && $sessionPath[1] == '\\')));
	if ($isRelative) {
		// Relative path
		$sessionPath = sys_get_temp_dir() . '/' . $sessionPath;
	}

	if (!is_dir($sessionPath)) {
		@mkdir($sessionPath, 0755, true);
	}

	// Configure session storage
	if (is_dir($sessionPath) && is_writable($sessionPath)) {
		ini_set('session.save_path', $sessionPath);
	} else {
		echo "Configuration error: Session path '$sessionPath' is not writable.";
		exit;
	}

	ini_set('session.save_handler', 'files');
}

// Session timeout duration in seconds
if (!empty($conf['session_timeout'])) {
	$sessionLifetime = (int)$conf['session_timeout'];
	ini_set('session.cookie_lifetime', $sessionLifetime);
	ini_set('session.gc_maxlifetime', $sessionLifetime);
}


// Start session (if not auto-started)
if (!ini_get('session.auto_start')) {
	session_name('PPA_ID');
	session_start();
}

// Always include english.php, since it's the master language file
if (!isset($conf['default_lang'])) $conf['default_lang'] = 'english';
$lang = array();
require_once __DIR__ . '/../lang/english.php';

// Determine language file to import

// 1. Check for the language from a request var
if (isset($_REQUEST['language']) && isset($appLangFiles[$_REQUEST['language']])) {
	/* save the selected language in cookie for a year */
	setcookie('webdbLanguage', $_REQUEST['language'], time() + 31536000);
	$_language = $_REQUEST['language'];
}

// 2. Check for language session var
if (!isset($_language) && isset($_SESSION['webdbLanguage']) && isset($appLangFiles[$_SESSION['webdbLanguage']])) {
	$_language = $_SESSION['webdbLanguage'];
}

// 3. Check for language in cookie var
if (!isset($_language) && isset($_COOKIE['webdbLanguage']) && isset($appLangFiles[$_COOKIE['webdbLanguage']])) {
	$_language = $_COOKIE['webdbLanguage'];
}

// 4. Check for acceptable languages in HTTP_ACCEPT_LANGUAGE var
if (!isset($_language) && $conf['default_lang'] == 'auto' && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
	// extract acceptable language tags
	// (http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.4)
	preg_match_all('/\s*([a-z]{1,8}(?:-[a-z]{1,8})*)(?:;q=([01](?:.[0-9]{0,3})?))?\s*(?:,|$)/', strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']), $_m, PREG_SET_ORDER);
	foreach ($_m as $_l) {  // $_l[1] = language tag, [2] = quality
		if (!isset($_l[2])) $_l[2] = 1;  // Default quality to 1
		if ($_l[2] > 0 && $_l[2] <= 1 && isset($availableLanguages[$_l[1]])) {
			// Build up array of (quality => language_file)
			$_acceptLang[$_l[2]] = $availableLanguages[$_l[1]];
		}
	}
	unset($_m);
	unset($_l);
	if (isset($_acceptLang)) {
		// Sort acceptable languages by quality
		krsort($_acceptLang, SORT_NUMERIC);
		$_language = reset($_acceptLang);
		unset($_acceptLang);
	}
}

// 5. Otherwise resort to the default set in the config file
if (!isset($_language) && $conf['default_lang'] != 'auto' && isset($appLangFiles[$conf['default_lang']])) {
	$_language = $conf['default_lang'];
}

// 6. Otherwise, default to english.
if (!isset($_language))
	$_language = 'english';


// Import the language file
if (isset($_language)) {
	include_once __DIR__ . "/../lang/{$_language}.php";
	$_SESSION['webdbLanguage'] = $_language;
}

AppContainer::setLang($lang);


// Check php libraries
$php_libraries_requirements = [
	// required_function => name_of_the_php_library
	'pg_connect' => 'pgsql',
	'mb_strlen' => 'mbstring',
];
$missing_libraries = [];
foreach ($php_libraries_requirements as $func_name => $lib) {
	if (!function_exists($func_name)) {
		$missing_libraries[] = $lib;
	}
}
if (!empty($missing_libraries)) {
	if (count($missing_libraries) == 1) {
		printf($lang['strlibnotfound'], implode(', ', $missing_libraries));
	} else {
		printf($lang['strlibnotfound_plural'], implode(', ', $missing_libraries));
	}
	exit;
}

// Create Misc class references
$misc = new Misc();
AppContainer::setMisc($misc);


// This has to be deferred until after stripVar above
$misc->setHREF();
$misc->setForm();

if (isset($_POST['action'])) {
	$_POST[$_POST['action']] = $_POST['action'];
}

// Enforce PHP environment
ini_set('arg_separator.output', '&amp;');

// If login action is set, then set session variables
if (
	isset($_POST['loginServer']) && isset($_POST['loginUsername']) &&
	isset($_POST['loginPassword_' . md5($_POST['loginServer'])])
) {

	$_server_info = $misc->getServerInfo($_POST['loginServer']);

	$_server_info['username'] = $_POST['loginUsername'];
	$_server_info['password'] = $_POST['loginPassword_' . md5($_POST['loginServer'])];

	$misc->setServerInfo(null, $_server_info, $_POST['loginServer']);

	// Check for shared credentials
	if (isset($_POST['loginShared'])) {
		$_SESSION['sharedUsername'] = $_POST['loginUsername'];
		$_SESSION['sharedPassword'] = $_POST['loginPassword_' . md5($_POST['loginServer'])];
	}

	AppContainer::setShouldReloadTree(true);
}

/* select the theme */
unset($_theme);
if (!isset($conf['theme']))
	$conf['theme'] = 'default';

// 1. Check for the theme from a request var
if (isset($_REQUEST['theme']) && is_file("./themes/{$_REQUEST['theme']}/global.css")) {
	/* save the selected theme in cookie for a year */
	setcookie('ppaTheme', $_REQUEST['theme'], time() + 31536000);
	$_theme = $_SESSION['ppaTheme'] = $conf['theme'] = $_REQUEST['theme'];
}

// 2. Check for theme session var
if (!isset($_theme) && isset($_SESSION['ppaTheme']) && is_file("./themes/{$_SESSION['ppaTheme']}/global.css")) {
	$conf['theme'] = $_SESSION['ppaTheme'];
}

// 3. Check for theme in cookie var
if (!isset($_theme) && isset($_COOKIE['ppaTheme']) && is_file("./themes/{$_COOKIE['ppaTheme']}/global.css")) {
	$conf['theme'] = $_COOKIE['ppaTheme'];
}


// 4. Check for theme by server/db/user
$info = $misc->getServerInfo();

if (!empty($info)) {
	$_theme = '';

	if ((isset($info['theme']['default']))
		and is_file("./themes/{$info['theme']['default']}/global.css")
	)
		$_theme = $info['theme']['default'];

	if (
		isset($_REQUEST['database'])
		and isset($info['theme']['db'][$_REQUEST['database']])
		and is_file("./themes/{$info['theme']['db'][$_REQUEST['database']]}/global.css")
	)
		$_theme = $info['theme']['db'][$_REQUEST['database']];

	if (
		isset($info['username'])
		and isset($info['theme']['user'][$info['username']])
		and is_file("./themes/{$info['theme']['user'][$info['username']]}/global.css")
	)
		$_theme = $info['theme']['user'][$info['username']];

	if ($_theme !== '') {
		setcookie('ppaTheme', $_theme, time() + 31536000);
		$conf['theme'] = $_theme;
	}
}


$plugin_manager = new PluginManager($_language);
AppContainer::setPluginManager($plugin_manager);


// Create data accessor object, if necessary
if (empty($_ENV["SKIP_DB_CONNECTION"] ?? '')) {

	if (!isset($_REQUEST['server'])) {
		echo $lang['strnoserversupplied'];
		exit;
	}

	$_server_info = $misc->getServerInfo();

	/* starting with PostgreSQL 9.0, we can set the application name */
	putenv("PGAPPNAME={$appName}_{$appVersion}");

	// Redirect to the login form if not logged in
	if (!isset($_server_info['username'])) {
		include('./login.php');
		exit;
	}

	// Connect to the current database, or if one is not specified
	// then connect to the default database.
	if (isset($_REQUEST['database']))
		$_curr_db = $_REQUEST['database'];
	else
		$_curr_db = $_server_info['defaultdb'];

	require __DIR__ . '/../classes/database/Connection.php';

	// Connect to database and set the global $data variable
	$data = $misc->getDatabaseAccessor($_curr_db);
	AppContainer::setData($data);

	// If schema is defined and database supports schemas, then set the
	// schema explicitly.
	if (isset($_REQUEST['database']) && isset($_REQUEST['schema'])) {
		$status = (new SchemaActions())->setSchema($_REQUEST['schema']);
		//$status = $data->setSchema($_REQUEST['schema']);
		if ($status != 0) {
			echo $lang['strbadschema'];
			exit;
		}
		$data->_schema = $_REQUEST['schema'];
	}
}
