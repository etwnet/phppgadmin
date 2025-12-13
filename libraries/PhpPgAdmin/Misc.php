<?php

namespace PhpPgAdmin;

use ADORecordSet;
use Connection;
use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Database\ArrayRecordSet;
use PhpPgAdmin\Gui\NavLinksRenderer;
use PhpPgAdmin\Gui\TableRenderer;
use PhpPgAdmin\Gui\TabsRenderer;
use PhpPgAdmin\Gui\TopbarRenderer;
use PhpPgAdmin\Gui\TrailRenderer;

/**
 * Namespaced facade for the legacy Misc class.
 */
class Misc extends AbstractContext
{
	// Tracking string to include in HREFs
	var $href;
	// Tracking string to include in forms
	var $form;

	// GUI renderer delegates
	private $tabsRenderer = null;
	private $trailRenderer = null;
	private $topbarRenderer = null;
	private $navLinksRenderer = null;
	private $tableRenderer = null;

	/**
	 * Checks if dumps are properly set up
	 * @param $all (optional) True to check pg_dumpall, false to just check pg_dump
	 * @return bool True, dumps are set up, false otherwise
	 */
	function isDumpEnabled($all = false) {
		$info = $this->getServerInfo();
		return !empty($info[$all ? 'pg_dumpall_path' : 'pg_dump_path']);
	}

	/**
	 * Sets the href tracking variable
	 */
	function setHREF() {
		$this->href = $this->getHREF();
	}

	/**
	 * Get a href query string, excluding objects below the given object type (inclusive)
	 */
	function getHREF($exclude_from = null) {
		$href = '';
		if (isset($_REQUEST['server']) && $exclude_from != 'server') {
			$href .= 'server=' . urlencode($_REQUEST['server']);
			if (isset($_REQUEST['database']) && $exclude_from != 'database') {
				$href .= '&database=' . urlencode($_REQUEST['database']);
				if (isset($_REQUEST['schema']) && $exclude_from != 'schema') {
					$href .= '&schema=' . urlencode($_REQUEST['schema']);
				}
			}
		}
		return htmlentities($href);
	}

	function getSubjectParams($subject) {
		global $plugin_manager;

		$vars = array();

		switch ($subject) {
		case 'root':
			$vars = array(
				'params' => array(
					'subject' => 'root'
				)
			);
			break;
		case 'server':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'server'
			));
			break;
		case 'role':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'role',
				'action' => 'properties',
				'rolename' => $_REQUEST['rolename']
			));
			break;
		case 'database':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'database',
				'database' => $_REQUEST['database'],
			));
			break;
		case 'schema':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'schema',
				'database' => $_REQUEST['database'],
				'schema' => $_REQUEST['schema']
			));
			break;
		case 'table':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'table',
				'database' => $_REQUEST['database'],
				'schema' => $_REQUEST['schema'],
				'table' => $_REQUEST['table']
			));
			break;
		case 'selectrows':
			$vars = array(
				'url' => 'tables.php',
				'params' => array(
					'server' => $_REQUEST['server'],
					'subject' => 'table',
					'database' => $_REQUEST['database'],
					'schema' => $_REQUEST['schema'],
					'table' => $_REQUEST['table'],
					'action' => 'confselectrows'
				));
			break;
		case 'view':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'view',
				'database' => $_REQUEST['database'],
				'schema' => $_REQUEST['schema'],
				'view' => $_REQUEST['view']
			));
			break;
		case 'fulltext':
		case 'ftscfg':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'fulltext',
				'database' => $_REQUEST['database'],
				'schema' => $_REQUEST['schema'],
				'action' => 'viewconfig',
				'ftscfg' => $_REQUEST['ftscfg']
			));
			break;
		case 'function':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'function',
				'database' => $_REQUEST['database'],
				'schema' => $_REQUEST['schema'],
				'function' => $_REQUEST['function'],
				'function_oid' => $_REQUEST['function_oid']
			));
			break;
		case 'aggregate':
			$vars = array('params' => array(
				'server' => $_REQUEST['server'],
				'subject' => 'aggregate',
				'action' => 'properties',
				'database' => $_REQUEST['database'],
				'schema' => $_REQUEST['schema'],
				'aggrname' => $_REQUEST['aggrname'],
				'aggrtype' => $_REQUEST['aggrtype']
			));
			break;
		case 'column':
			if (isset($_REQUEST['table']))
				$vars = array('params' => array(
					'server' => $_REQUEST['server'],
					'subject' => 'column',
					'database' => $_REQUEST['database'],
					'schema' => $_REQUEST['schema'],
					'table' => $_REQUEST['table'],
					'column' => $_REQUEST['column']
				));
			else
				$vars = array('params' => array(
					'server' => $_REQUEST['server'],
					'subject' => 'column',
					'database' => $_REQUEST['database'],
					'schema' => $_REQUEST['schema'],
					'view' => $_REQUEST['view'],
					'column' => $_REQUEST['column']
				));
			break;
		case 'plugin':
			$vars = array(
				'url' => 'plugin.php',
				'params' => array(
					'server' => $_REQUEST['server'],
					'subject' => 'plugin',
					'plugin' => $_REQUEST['plugin'],
				));

			if (!is_null($plugin_manager->getPlugin($_REQUEST['plugin'])))
				$vars['params'] = array_merge($vars['params'], $plugin_manager->getPlugin($_REQUEST['plugin'])->get_subject_params());
			break;
		default:
			return false;
		}

		if (!isset($vars['url']))
			$vars['url'] = 'redirect.php';

		return $vars;
	}

	function getHREFSubject($subject) {
		$vars = $this->getSubjectParams($subject);
		return "{$vars['url']}?" . http_build_query($vars['params'], '', '&amp;');
	}

	/**
	 * Sets the form tracking variable
	 */
	function setForm() {
		$this->form = '';
		if (isset($_REQUEST['server'])) {
			$this->form .= "<input type=\"hidden\" name=\"server\" value=\"" . htmlspecialchars($_REQUEST['server']) . "\" />\n";
			if (isset($_REQUEST['database'])) {
				$this->form .= "<input type=\"hidden\" name=\"database\" value=\"" . htmlspecialchars($_REQUEST['database']) . "\" />\n";
				if (isset($_REQUEST['schema'])) {
					$this->form .= "<input type=\"hidden\" name=\"schema\" value=\"" . htmlspecialchars($_REQUEST['schema']) . "\" />\n";
				}
			}
		}
	}

	/**
	 * Render a value into HTML using formatting rules specified
	 * by a type name and parameters.
	 *
	 * @param $str The string to change
	 *
	 * @param $type Field type (optional), this may be an internal PostgreSQL type, or:
	 *         yesno    - same as bool, but renders as 'Yes' or 'No'.
	 *         pre      - render in a <pre> block.
	 *         nbsp     - replace all spaces with &nbsp;'s
	 *         verbatim - render exactly as supplied, no escaping what-so-ever.
	 *         callback - render using a callback function supplied in the 'function' param.
	 *
	 * @param $params Type parameters (optional), known parameters:
	 *         null     - string to display if $str is null, or set to TRUE to use a default 'NULL' string,
	 *                    otherwise nothing is rendered.
	 *         clip     - if true, clip the value to a fixed length, and append an ellipsis...
	 *         cliplen  - the maximum length when clip is enabled (defaults to $conf['max_chars'])
	 *         ellipsis - the string to append to a clipped value (defaults to $lang['strellipsis'])
	 *         tag      - an HTML element name to surround the value.
	 *         class    - a class attribute to apply to any surrounding HTML element.
	 *         align    - an align attribute ('left','right','center' etc.)
	 *         true     - (type='bool') the representation of true.
	 *         false    - (type='bool') the representation of false.
	 *         function - (type='callback') a function name, accepts args ($str, $params) and returns a rendering.
	 *         lineno   - prefix each line with a line number.
	 *         map      - an associative array.
	 *
	 * @return The HTML rendered value
	 */
	function printVal($str, $type = null, $params = array()) {
		global $lang, $conf, $data;

		// Shortcircuit for a NULL value
		if (is_null($str))
			return isset($params['null'])
				? ($params['null'] === true ? '<i class="null">NULL</i>' : $params['null'])
				: '';

		if (isset($params['map']) && isset($params['map'][$str])) $str = $params['map'][$str];

		// Clip the value if the 'clip' parameter is true.
		if (isset($params['clip']) && $params['clip'] === true) {
			$maxlen = isset($params['cliplen']) && is_integer($params['cliplen']) ? $params['cliplen'] : $conf['max_chars'];
			$ellipsis = $params['ellipsis'] ?? $lang['strellipsis'];
			if (mb_strlen($str, 'UTF-8') > $maxlen) {
				$str = mb_substr($str, 0, $maxlen - 1, 'UTF-8') . $ellipsis;
			}
		}

		$out = '';

		switch ($type) {
		case 'int2':
		case 'int4':
		case 'int8':
		case 'float4':
		case 'float8':
		case 'money':
		case 'numeric':
		case 'oid':
		case 'xid':
		case 'cid':
		case 'tid':
			$align = 'right';
			$out = nl2br(htmlspecialchars($str));
			break;
		case 'yesno':
			if (!isset($params['true'])) $params['true'] = $lang['stryes'];
			if (!isset($params['false'])) $params['false'] = $lang['strno'];
			// No break - fall through to boolean case.
		case 'bool':
		case 'boolean':
			if (is_bool($str)) $str = $str ? 't' : 'f';
			switch ($str) {
			case 't':
				$out = ($params['true'] ?? $lang['strtrue']);
				$align = 'center';
				break;
			case 'f':
				$out = ($params['false'] ?? $lang['strfalse']);
				$align = 'center';
				break;
			default:
				$out = htmlspecialchars($str);
			}
			break;
		case 'bytea':
			$tag = 'div';
			$class = 'pre';
			$out = $data->escapeBytea($str);
			break;
		case 'errormsg':
			$tag = 'pre';
			$class = 'error';
			$out = htmlspecialchars($str);
			break;
		case 'pre':
			$tag = 'pre';
			$out = htmlspecialchars($str);
			break;
		case 'prenoescape':
			$tag = 'pre';
			$out = $str;
			break;
		case 'sql':
			$tag = 'pre';
			$class = 'sql-viewer';
			$out = htmlspecialchars($str);
			break;
		case 'nbsp':
			$out = nl2br(str_replace(' ', '&nbsp;', htmlspecialchars($str)));
			break;
		case 'verbatim':
			$out = $str;
			break;
		case 'callback':
			$out = $params['function']($str, $params);
			break;
		case 'prettysize':
			if ($str == -1)
				$out = $lang['strnoaccess'];
			else {
				$limit = 10 * 1024;
				$mult = 1;
				if ($str < $limit * $mult)
					$out = $str . ' ' . $lang['strbytes'];
				else {
					$mult *= 1024;
					if ($str < $limit * $mult)
						$out = floor(($str + $mult / 2) / $mult) . ' ' . $lang['strkb'];
					else {
						$mult *= 1024;
						if ($str < $limit * $mult)
							$out = floor(($str + $mult / 2) / $mult) . ' ' . $lang['strmb'];
						else {
							$mult *= 1024;
							if ($str < $limit * $mult)
								$out = floor(($str + $mult / 2) / $mult) . ' ' . $lang['strgb'];
							else {
								$mult *= 1024;
								if ($str < $limit * $mult)
									$out = floor(($str + $mult / 2) / $mult) . ' ' . $lang['strtb'];
							}
						}
					}
				}
			}
			break;
		default:
			/*
			// If the string contains at least one instance of >1 space in a row, a tab
			// character, a space at the start of a line, or a space at the start of
			// the whole string then render within a pre-formatted element (<pre>).
			if (preg_match('/(^ |  |\t|\n )/m', $str)) {
				$tag = 'pre';
				$class = 'data';
				$out = htmlspecialchars($str);
			} else {
				$out = nl2br(htmlspecialchars($str));
			}
			*/
			$out = nl2br(htmlspecialchars($str));
		}

		if (isset($params['class'])) $class = $params['class'];
		if (isset($params['align'])) $align = $params['align'];

		if (!isset($tag) && (isset($class) || isset($align))) $tag = 'div';

		if (isset($tag)) {
			$alignattr = isset($align) ? " style=\"text-align: {$align}\"" : '';
			$classattr = isset($class) ? " class=\"{$class}\"" : '';
			$out = "<{$tag}{$alignattr}{$classattr}>{$out}</{$tag}>";
		}

		// Add line numbers if 'lineno' param is true
		if (isset($params['lineno']) && $params['lineno'] === true) {
			$lines = explode("\n", $str);
			$num = count($lines);
			if ($num > 0) {
				$temp = "<table>\n<tr><td class=\"{$class}\" style=\"vertical-align: top; padding-right: 10px;\"><pre class=\"{$class}\">";
				for ($i = 1; $i <= $num; $i++) {
					$temp .= $i . "\n";
				}
				$temp .= "</pre></td><td class=\"{$class}\" style=\"vertical-align: top;\">{$out}</td></tr></table>\n";
				$out = $temp;
			}
			unset($lines);
		}

		return $out;
	}

	/**
	 * A function to recursively strip slashes.  Used to
	 * enforce magic_quotes_gpc being off.
	 * @param &var The variable to strip
	 */
	function stripVar(&$var) {
		if (is_array($var)) {
			foreach ($var as $k => $v) {
				$this->stripVar($var[$k]);

				/* magic_quotes_gpc escape keys as well ...*/
				if (is_string($k)) {
					$ek = stripslashes($k);
					if ($ek !== $k) {
						$var[$ek] = $var[$k];
						unset($var[$k]);
					}
				}
			}
		} else
			$var = stripslashes($var);
	}

	/**
	 * Print out the page heading and help link
	 * @param string $title Title, already escaped
	 * @param $help (optional) The identifier for the help link
	 */
	function printTitle($title, $help = null) {
		global $data, $lang;

		echo "<h2>";
		$this->printHelp($title, $help);
		echo "</h2>\n";
	}

	/**
	 * Print out a message
	 * @param $msg The message to print
	 */
	function printMsg($msg) {
		if ($msg != '') echo "<p class=\"message\">{$msg}</p>\n";
	}

	/**
	 * Creates a database accessor
	 */
	function getDatabaseAccessor($database, $server_id = null) {
		global $lang, $conf, $misc, $_connection, $postgresqlMinVer;

		$server_info = $this->getServerInfo($server_id);

		// Perform extra security checks if this config option is set
		if ($conf['extra_login_security']) {
			// Disallowed logins if extra_login_security is enabled.
			// These must be lowercase.
			$bad_usernames = array('pgsql', 'postgres', 'root', 'administrator');

			$username = strtolower($server_info['username']);

			if ($server_info['password'] == '' || in_array($username, $bad_usernames)) {
				unset($_SESSION['webdbLogin'][$_REQUEST['server']]);
				$msg = $lang['strlogindisallowed'];
				include('./login.php');
				exit;
			}
		}

		// Create the connection object and make the connection
		$_connection = new Connection(
			$server_info['host'],
			$server_info['port'],
			$server_info['sslmode'],
			$server_info['username'],
			$server_info['password'],
			$database
		);

		// Get the name of the database driver we need to use.
		// The description of the server is returned in $platform.
		$_type = $_connection->getDriver($platform);
		if ($_type === null) {
			printf($lang['strpostgresqlversionnotsupported'], $postgresqlMinVer);
			exit;
		}
		$this->setServerInfo('platform', $platform, $server_id);
		$this->setServerInfo('pgVersion', $_connection->conn->pgVersion ?? '', $server_id);

		// Create a database wrapper class for easy manipulation of the
		// connection.
		include_once('./classes/database/' . $_type . '.php');
		$data = new $_type($_connection->conn);
		$data->platform = $_connection->platform;

		/* we work on UTF-8 only encoding */
		$data->execute("SET client_encoding TO 'UTF-8'");

		if ($data->hasByteaHexDefault()) {
			$data->execute("SET bytea_output TO escape");
		}

		return $data;
	}

	public $ajaxRequest = false;
	public $frameContentRequest = false;

	/**
	 * Prints the page header.  If global variable $_no_html_frame is
	 * set then no header is drawn.
	 * @param string $title The title of the page
	 * @param string $scripts script tags
	 */
	function printHeader($title = '', $scripts = '') {
		global $appName, $lang, $_no_html_frame, $conf, $plugin_manager;

		// capture ajax/json requests
		if (!empty($_REQUEST['ajax'])) {
			$this->ajaxRequest = true;
			if ($_REQUEST['ajax'] == 'json') {
				header("Content-Type: application/json; charset=utf-8");
			} else {
				header("Content-Type: text/html; charset=utf-8");
			}
			return;
		}

		$title = htmlspecialchars($appName . (empty($title) ? '' : " - $title"));

		// skip html frame for inner content links
		if ($_REQUEST['target'] ?? '' == 'content') {
			$this->frameContentRequest = true;
			$_no_html_frame = true;
			// update title through javascript
			echo "<script>\n";
			echo "document.title = \"$title\";\n";
			echo "</script>\n";
		}

		// just output scripts and return
		if (!empty($_no_html_frame)) {
			echo $scripts;
			return;
		}

		$langIso2 = substr($lang['applocale'], 0, 2);

		header("Content-Type: text/html; charset=utf-8");
		echo "<!DOCTYPE html>\n";
		echo '<html lang="' . $lang['applocale'] . '" dir="' . htmlspecialchars($lang['applangdir']) . '">';
		echo "\n";
		?>

		<head>
			<meta charset="utf-8"/>
			<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
			<link rel="stylesheet" href="js/flatpickr/flatpickr.css" type="text/css">
			<link rel="stylesheet" href="themes/<?= $conf['theme'] ?>/global.css" type="text/css" id="csstheme">
			<link rel="icon" type="image/png" href="images/themes/<?= $conf['theme'] ?>/pgadmin.png"/>
			<script src="js/jquery.js" type="text/javascript"></script>
			<script src="js/xtree2.js" type="text/javascript"></script>
			<script src="js/xloadtree2.js" type="text/javascript"></script>
			<script src="js/popper.js" defer type="text/javascript"></script>
			<script src="js/flatpickr/flatpickr.js" defer type="text/javascript"></script>
			<?php if ($langIso2 != 'en') : ?>
				<script src="js/flatpickr/l10n/<?= $langIso2 ?>.js" defer type="text/javascript"></script>
			<?php endif ?>
			<script src="libraries/ace/src-min-noconflict/ace.js" defer type="text/javascript"></script>
			<script src="libraries/ace/src-min-noconflict/mode-pgsql.js" defer type="text/javascript"></script>
			<script src="js/frameset.js" defer type="text/javascript"></script>
			<script src="js/misc.js" defer type="text/javascript"></script>
			<style>
			 .webfx-tree-children {
				 background-image: url("<?= $this->icon('I') ?> ");
			 }
			 .calendar-icon-bg {
				 background-image: url("<?= $this->icon('Calendar') ?> ");
			 }
			</style>
			<title><?= $title ?></title>
			<?= $scripts ?>

			<?php
			$plugins_head = [];
			$_params = ['heads' => &$plugins_head];
			$plugin_manager->do_hook('head', $_params);
			foreach ($plugins_head as $tag) {
				echo $tag;
			}
			?>
		</head>
		<?php
	}

	private $hasFrameset = false;

	/**
	 * Prints the page body.
	 */
	function printBody() {
		global $_no_html_frame, $lang;

		if (!empty($_no_html_frame) || $this->ajaxRequest) {
			return;
		}
		$this->hasFrameset = true;
		echo <<<EOT
		<body>
		<div id="tooltip" role="tooltip">
		  <div id="tooltip-content"></div>
		  <div class="arrow" data-popper-arrow></div>
		</div>
		<div id="loading-indicator">
			<svg class="spinner" viewBox="0 0 66 66" xmlns="http://www.w3.org/2000/svg">
			   <circle class="path" fill="none" stroke-width="6" stroke-linecap="round" cx="33" cy="33" r="30"></circle>
			</svg>
		</div>
		<div id="frameset">
			<div id="tree" dir="ltr">
EOT;
		$this->printBrowser();
		echo <<<EOT
			</div>
			<div id="resizer"></div>
			<div id="content-container">
				<!-- anything inside #content will be overwritten... -->
				<div id="content">
EOT;
	}

	/**
	 * Prints the page footer
	 */
	function printFooter() {
		global $_reload_browser, $_reload_tree;
		global $lang, $_no_bottom_link, $_no_html_frame;

		if ($this->ajaxRequest) {
			return;
		}

		if (isset($_reload_browser)) {
			$this->printReload(false);
		} elseif (isset($_reload_tree)) {
			$this->printReload(true);
		}

		if ($this->hasFrameset || $this->frameContentRequest) {
			echo "<hr>\n";
			echo "<div class=\"clearfix bottom-footer\">\n";
			echo "<a href=\"\" target=\"_blank\" class=\"new_window\" title=\"", htmlspecialchars($lang['strnewwindow']), "\"><img src=\"", $this->icon("NewWindow"), "\" ></a>";
			echo "</div>\n";
		}

		if (!isset($_no_bottom_link)) {
			echo "<a href=\"#\" class=\"bottom_link\">⇱</a>";
		}

		if (!empty($_no_html_frame)) {
			return;
		}
		if ($this->hasFrameset) {
			echo "</div>\n"; // close #content div
			echo "</div>\n"; // close #content-container div
			echo "</div>\n"; // close #frameset div
		}
		echo "</body>\n";
		echo "</html>\n";
	}

	function printBrowser() {
		global $appName, $lang;
		?>
		<div class="logo">
			<a href="index.php">
				<?= htmlspecialchars($appName) ?>
			</a>
		</div>
		<div class="refreshTree">
			<a href="#" onclick="writeTree()"><img class="icon" src="<?= $this->icon('Refresh'); ?>" alt="<?= $lang['strrefresh']; ?>" title="<?= $lang['strrefresh']; ?>"/></a>
		</div>
		<div id="wfxt-container"></div>
		<script>

			webFXTreeConfig.rootIcon = "<?= $this->icon('Servers') ?>";
			webFXTreeConfig.openRootIcon = "<?= $this->icon('Servers') ?>";
			webFXTreeConfig.folderIcon = "";
			webFXTreeConfig.openFolderIcon = "";
			webFXTreeConfig.fileIcon = "";
			webFXTreeConfig.iIcon = "<?= $this->icon('I') ?>";
			webFXTreeConfig.lIcon = "<?= $this->icon('L') ?>";
			webFXTreeConfig.lMinusIcon = "<?= $this->icon('Lminus') ?>";
			webFXTreeConfig.lPlusIcon = "<?= $this->icon('Lplus') ?>";
			webFXTreeConfig.tIcon = "<?= $this->icon('T') ?>";
			webFXTreeConfig.tMinusIcon = "<?= $this->icon('Tminus') ?>";
			webFXTreeConfig.tPlusIcon = "<?= $this->icon('Tplus') ?>";
			webFXTreeConfig.blankIcon = "<?= $this->icon('blank') ?>";
			webFXTreeConfig.loadingIcon = "<?= $this->icon('Loading') ?>";
			webFXTreeConfig.loadingText = "<?= $lang['strloading'] ?>";
			webFXTreeConfig.errorIcon = "<?= $this->icon('ObjectNotFound') ?>";
			webFXTreeConfig.errorLoadingText = "<?= $lang['strerrorloading'] ?>";
			webFXTreeConfig.reloadText = "<?= $lang['strclicktoreload'] ?>";

			// Set default target frame:
			//WebFXTreeAbstractNode.prototype.target = 'detail';

			// Disable double click:
			WebFXTreeAbstractNode.prototype._ondblclick = function () {
			}

			// Show tree XML on double click - for debugging purposes only
			/*
			// UNCOMMENT THIS FOR DEBUGGING (SHOWS THE SOURCE XML)
			WebFXTreeAbstractNode.prototype._ondblclick = function(e){
				var el = e.target || e.srcElement;

				if (this.src != null)
					window.open(this.src, this.target || "_self");
				return false;
			};
			*/

			function writeTree() {
				webFXTreeHandler.idCounter = 0;
				const tree = new WebFXLoadTree(
					"<?= $lang['strservers']; ?>",
					"servers.php?action=tree",
					"servers.php"
				);
				tree.write("wfxt-container");
				tree.setExpanded(true);
			}

			writeTree();

		</script>
		<?php
	}

	/**
	 * Outputs JavaScript code that will reload the browser
	 * @param $tree bool True if dropping a database, false otherwise
	 */
	function printReload($tree) {
		echo "<script>\n";
		if ($tree) {
			echo "\twriteTree();\n";
		} else {
			echo "\twindow.location.replace(\"index.php\");\n";
		}
		echo "</script>\n";
	}

	/**
	 * @param string $location
	 */
	function redirect(string $location) {
		header("Location: $location");
		exit;
	}

	/**
	 * Display a link
	 * @param array $link An associative array of link parameters to print
	 *     link = array(
	 *       'attr' => array( // list of A tag attribute
	 *          'attrname' => attribute value
	 *          ...
	 *       ),
	 *       'content' => The link text
	 *       'fields' => (optional) the data from which content and attr's values are obtained
	 *     );
	 *   the special attribute 'href' might be a string or an array. If href is an array it
	 *   will be generated by getActionUrl. See getActionUrl comment for array format.
	 */
	function printLink($link) {

		if (!isset($link['fields']))
			$link['fields'] = $_REQUEST;

		$tag = "<a ";
		foreach ($link['attr'] as $attr => $value) {
			if ($attr == 'href' and is_array($value)) {
				$tag .= 'href="' . htmlentities($this->getActionUrl($value, $link['fields'])) . '" ';
			} else {
				$tag .= htmlentities($attr) . '="' . value($value, $link['fields'], 'html') . '" ';
			}
		}
		$tag .= ">";
		if (!empty($link['icon'])) {
			$tag .= "<img class=\"icon\" src=\"{$link['icon']}\" alt=\"" . htmlspecialchars($link['content'] ?? '') . "\">";
		}
		if (!empty($link['content'])) {
			$tag .= value($link['content'], $link['fields'], 'html');
		}
		$tag .= "</a>\n";
		echo $tag;
		//var_dump($link['content']);
		//throw new Exception("");
	}

	/**
	 * Display a list of links
	 * @param $links An associative array of links to print. See printLink function for
	 *               the links array format.
	 * @param $class An optional class or list of classes seprated by a space
	 *   WARNING: This field is NOT escaped! No user should be able to inject something here, use with care.
	 */
	function printLinksList($links, $class = '') {
		echo "<ul class=\"{$class}\">\n";
		foreach ($links as $link) {
			echo "\t<li>";
			$this->printLink($link);
			echo "</li>\n";
		}
		echo "</ul>\n";
	}

	/**
	 * Display navigation tabs
	 * @param $tabs The name of current section (Ex: intro, server, ...), or an array with tabs (Ex: sqledit.php doFind function)
	 * @param $activetab The name of the tab to be highlighted.
	 */
	function printTabs($tabs, $activetab) {
		return $this->getTabsRenderer()->printTabs($tabs, $activetab);
	}

	/**
	 * Retrieve the tab info for a specific tab bar.
	 * @param $section The name of the tab bar.
	 */
	function getNavTabs($section) {
		return $this->getTabsRenderer()->getNavTabs($section);
	}

	/**
	 * Get the URL for the last active tab of a particular tab bar.
	 */
	function getLastTabURL($section) {
		return $this->getTabsRenderer()->getLastTabURL($section);
	}

	function printTopbar() {
		return $this->getTopbarRenderer()->printTopbar();
	}

	/**
	 * Display a bread crumb trail.
	 */
	function printTrail($trail = array()) {
		$this->printTopbar();
		return $this->getTrailRenderer()->printTrail($trail);
	}

	/**
	 * Create a bread crumb trail of the object hierarchy.
	 * @param string $subject The type of object at the end of the trail.
	 */
	function getTrail($subject = null) {
		return $this->getTrailRenderer()->getTrail($subject);
	}

	/**
	 * Display the navlinks
	 *
	 * @param array $navlinks - An array with the the attributes and values that
	 * will be shown. See printLinksList for array format.
	 * @param string $place - Place where the $navlinks are displayed.
	 * Like 'display-browse', where 'display' is the file (display.php)
	 * @param array $env - Associative array of defined variables in the scope
	 * of the caller. Allows to give some environment details to plugins.
	 * and 'browse' is the place inside that code (doBrowse).
	 */
	function printNavLinks($navlinks, $place, $env = array()) {
		return $this->getNavLinksRenderer()->printNavLinks($navlinks, $place, $env);
	}


	/**
	 * Do multi-page navigation.  Displays the prev, next and page options.
	 * @param int $page - the page currently viewed
	 * @param int $pages - the maximum number of pages
	 * @param array $gets -  the parameters to include in the link to the wanted page
	 */
	function printPageNavigation($page, $pages, $gets) {
		global $conf, $lang;
		static $limits = null;
		if (!isset($limits)) {
			$limits = [10, 50, 100, 250, 500, 1000, $conf['max_rows']];
			sort($limits, SORT_NUMERIC);
		}
		$window = 3;

		if ($page < 1 || $page > $pages) return;
		if ($pages < 1) return;

		unset($gets['page']);
		$url = http_build_query($gets);

		echo "<div class=\"pagenav-container\">\n";

		echo "<form method=\"get\" action=\"?$url\" class=\"pagenav-form\">";
		echo "<span class=\"me-1\">{$lang['strjumppage']}</span>\n";
		echo "<input type=\"number\" class=\"page\" name=\"page\" min=\"1\" max=\"$pages\" value=\"$page\">\n";
		echo "<button type=\"submit\">↩</button>\n";
		echo "</form>\n";


		$class = ($page > 1) ? "pagenav" : "pagenav disabled";
		echo "<a class=\"$class\" href=\"?{$url}&page=" . max(1, $page-1) . "\">⮜</a>\n";

		echo "<a class=\"pagenav" . ($page == 1 ? " current" : "") . "\" href=\"?{$url}&page=1\">1</a>\n";

		$min_page = $page - $window;
		$max_page = $page + $window;

		if ($min_page < 2) {
			$shift = 2 - $min_page;
			$min_page = 2;
			$max_page = min($pages - 1, $max_page + $shift);
		}

		if ($max_page > $pages - 1) {
			$shift = $max_page - ($pages - 1);
			$max_page = $pages - 1;
			$min_page = max(2, $min_page - $shift);
		}

		if ($min_page > 2) {
			echo "<span class=\"ellipsis\">…</span>\n";
		}

		for ($i = $min_page; $i <= $max_page; $i++) {
			$class = ($i == $page) ? "pagenav current" : "pagenav";
			echo "<a class=\"$class\" href=\"?{$url}&page={$i}\">$i</a>\n";
		}

		if ($max_page < $pages - 1) {
			echo "<span class=\"ellipsis\">…</span>\n";
		}

		if ($pages > 1) {
			echo "<a class=\"pagenav" . ($page == $pages ? " current" : "") . "\" href=\"?{$url}&page={$pages}\">$pages</a>\n";
		}

		$class = ($page < $pages) ? "pagenav" : "pagenav disabled";
		echo "<a class=\"$class\" href=\"?{$url}&page=" . ($page+1) . "\">⮞</a>\n";

		$query_params = $gets;
		unset($query_params['max_rows']);
		$sub_url = http_build_query($query_params);
		echo "<form method=\"get\" action=\"?$sub_url\" class=\"pagenav-form ml-2\">";
		echo "<span class=\"me-1\">{$lang['strselectmaxrows']}</span>\n";
		echo "<select name=\"max_rows\" class=\"max_rows\" onchange=\"this.form.querySelector('button[type=submit]').click()\">\n";
		foreach ($limits as $limit) {
			$selected = ($limit == $gets['max_rows']) ? ' selected' : '';
			echo "<option value=\"$limit\"{$selected}>$limit</option>\n";
		}
		echo "</select>\n";
		echo "<button type=\"submit\" style=\"display:none;\"></button>\n";
		echo "</form>\n";

		echo "</div>\n";
	}

	/**
	 * Displays link to the context help.
	 * @param string $str - the string that the context help is related to (already escaped)
	 * @param string $help - help section identifier
	 */
	function printHelp($str, $help) {
		global $lang, $data;

		echo $str;
		if ($help) {
			echo "<a class=\"help\" href=\"";
			echo htmlspecialchars("help.php?help=" . urlencode($help) . "&server=" . urlencode($_REQUEST['server']));
			echo "\" title=\"{$lang['strhelp']}\" target=\"phppgadminhelp\">{$lang['strhelpicon']}</a>";
		}
	}

	/**
	 * Outputs JavaScript to set default focus
	 * @param string $object eg. forms[0].username
	 */
	function setFocus($object) {
		echo "<script type=\"text/javascript\">\n";
		echo "document.{$object}.focus();\n";
		echo "</script>\n";
	}

	private function getTabsRenderer() {
		if ($this->tabsRenderer === null) {
			$this->tabsRenderer = new TabsRenderer();
		}

		return $this->tabsRenderer;
	}

	private function getTrailRenderer() {
		if ($this->trailRenderer === null) {
			$this->trailRenderer = new TrailRenderer($this);
		}

		return $this->trailRenderer;
	}

	private function getTopbarRenderer() {
		if ($this->topbarRenderer === null) {
			$this->topbarRenderer = new TopbarRenderer($this);
		}

		return $this->topbarRenderer;
	}

	private function getNavLinksRenderer() {
		if ($this->navLinksRenderer === null) {
			$this->navLinksRenderer = new NavLinksRenderer($this);
		}

		return $this->navLinksRenderer;
	}

	private function getTableRenderer() {
		if ($this->tableRenderer === null) {
			$this->tableRenderer = new TableRenderer($this);
		}

		return $this->tableRenderer;
	}

	/**
	 * Outputs JavaScript to set the name of the browser window.
	 * @param string $name the window name
	 * @param bool $addServer if true (default) then the server id is
	 *        attached to the name.
	 */
	function setWindowName($name, $addServer = true) {
		echo "<script type=\"text/javascript\">\n";
		echo "window.name = '{$name}", ($addServer ? ':' . htmlspecialchars($_REQUEST['server']) : ''), "';\n";
		echo "</script>\n";
	}

	/**
	 * Converts a PHP.INI size variable to bytes.  Taken from publicly available
	 * function by Chris DeRose, here: http://www.php.net/manual/en/configuration.directives.php#ini.file-uploads
	 * @param string $strIniSize The PHP.INI variable
	 * @return int size in bytes, false on failure
	 */
	function inisizeToBytes($strIniSize) {
		// This function will take the string value of an ini 'size' parameter,
		// and return a double (64-bit float) representing the number of bytes
		// that the parameter represents. Or false if $strIniSize is unparsable.
		$a_IniParts = array();

		if (!is_string($strIniSize))
			return false;

		if (!preg_match('/^(\d+)([bkm]*)$/i', $strIniSize, $a_IniParts))
			return false;

		$nSize = (double)$a_IniParts[1];
		$strUnit = strtolower($a_IniParts[2]);

		switch ($strUnit) {
		case 'm':
			return ($nSize * (double)1048576);
		case 'k':
			return ($nSize * (double)1024);
		case 'b':
		default:
			return $nSize;
		}
	}

	/**
	 * Returns URL given an action associative array.
	 * NOTE: this function does not html-escape, only url-escape
	 * @param array $action An associative array of the follow properties:
	 *         'url'  => The first part of the URL (before the ?)
	 *         'urlvars' => Associative array of (URL variable => field name)
	 *                  these are appended to the URL
	 * @param array $fields Field data from which 'urlfield' and 'vars' are obtained.
	 */
	function getActionUrl($action, $fields) {
		$url = value($action['url'], $fields);

		if ($url === false) return '';

		if (!empty($action['urlvars'])) {
			$urlvars = value($action['urlvars'], $fields);
		} else {
			$urlvars = array();
		}

		/* set server, database and schema parameter if not presents */
		if (isset($urlvars['subject']))
			$subject = value($urlvars['subject'], $fields);
		else
			$subject = '';

		if (isset($_REQUEST['server']) and !isset($urlvars['server']) and $subject != 'root') {
			$urlvars['server'] = $_REQUEST['server'];
			if (isset($_REQUEST['database']) and !isset($urlvars['database']) and $subject != 'server') {
				$urlvars['database'] = $_REQUEST['database'];
				if (isset($_REQUEST['schema']) and !isset($urlvars['schema']) and $subject != 'database') {
					$urlvars['schema'] = $_REQUEST['schema'];
				}
			}
		}

		$sep = '?';
		foreach ($urlvars as $var => $varfield) {
			if (is_array($varfield)) {
				// 'orderby' => [ 'id' => 'asc' ]
				$param = http_build_query([$var => $varfield]);
			} else {
				$param = value_url($var, $fields) . '=' . value_url($varfield, $fields);
			}
			if (!empty($param)) {
				$url .= $sep;
				$url .= $param;
				$sep = '&';
			}
		}

		return $url;
	}

	/**
	 * @param string $subject
	 * @return array
	 */
	function getRequestVars($subject = '') {
		$v = array();
		if (!empty($subject))
			$v['subject'] = $subject;
		if (isset($_REQUEST['server']) && $subject != 'root') {
			$v['server'] = $_REQUEST['server'];
			if (isset($_REQUEST['database']) && $subject != 'server') {
				$v['database'] = $_REQUEST['database'];
				if (isset($_REQUEST['schema']) && $subject != 'database') {
					$v['schema'] = $_REQUEST['schema'];
				}
			}
		}
		return $v;
	}

	/**
	 * @param array $vars
	 * @param array $fields
	 */
	function printUrlVars($vars, $fields) {
		foreach ($vars as $var => $varfield) {
			echo "{$var}=", urlencode($fields[$varfield]), "&amp;";
		}
	}

	/**
	 * Display a table of data.
	 * @param ADORecordSet $tabledata  A set of data to be formatted, as returned by $data->getDatabases() etc.
	 * @param array $columns   An associative array of columns to be displayed:
	 *         $columns = array(
	 *            column_id => array(
	 *               'title' => Column heading,
	 *               'class' => The class to apply on the column cells,
	 *               'field' => Field name for $tabledata->fields[...],
	 *               'help'  => Help page for this column,
	 *            ), ...
	 *         );
	 * @param array $actions   Actions that can be performed on each object:
	 *         $actions = array(
	 *            * multi action support
	 *            * parameters are serialized for each entries and given in $_REQUEST['ma']
	 *            'multiactions' => array(
	 *               'keycols' => Associative array of (URL variable => field name), // fields included in the form
	 *               'url' => URL submission,
	 *               'default' => Default selected action in the form.
	 *                           if null, an empty action is added & selected
	 *            ),
	 *            * actions *
	 *            action_id => array(
	 *               'title' => Action heading,
	 *               'url'   => Static part of URL.  Often we rely
	 *                        relative urls, usually the page itself (not '' !), or just a query string,
	 *               'vars'  => Associative array of (URL variable => field name),
	 *               'multiaction' => Name of the action to execute.
	 *                              Add this action to the multi action form
	 *            ), ...
	 *         );
	 * @param string $place     Place where the $actions are displayed. Like 'display-browse', where 'display' is the file (display.php)
	 *                   and 'browse' is the place inside that code (doBrowse).
	 * @param string $nodata    (optional) Message to display if data set is empty.
	 * @param callable $pre_fn    (optional) Name of a function to call for each row,
	 *                it will be passed two params: $rowdata and $actions,
	 *                it may be used to derive new fields or modify actions.
	 *                It can return an array of actions specific to the row,
	 *                or if nothing is returned then the standard actions are used.
	 *                (see tblproperties.php and constraints.php for examples)
	 *                The function must not must not store urls because
	 *                they are relative and won't work out of context.
	 */
	function printTable($tabledata, &$columns, &$actions, $place, $nodata = null, $pre_fn = null) {
		return $this->getTableRenderer()->printTable($tabledata, $columns, $actions, $place, $nodata, $pre_fn);
	}

	/** Produce XML data for the browser tree
	 * @param \ADORecordSet $treedata A set of records to populate the tree.
	 * @param array $attrs Attributes for tree items
	 *        'text' - the text for the tree node
	 *        'icon' - an icon for node
	 *        'openIcon' - an alternative icon when the node is expanded
	 *        'toolTip' - tool tip text for the node
	 *        'action' - URL to visit when single clicking the node
	 *        'iconAction' - URL to visit when single clicking the icon node
	 *        'branch' - URL for child nodes (tree XML)
	 *        'expand' - the action to return XML for the subtree
	 *        'nodata' - message to display when node has no children
	 * @param mixed $section The section where the branch is linked in the tree
	 */
	function printTree($_treedata, &$attrs, $section) {
		global $plugin_manager;

		$treedata = array();

		if ($_treedata->recordCount() > 0) {
			while (!$_treedata->EOF) {
				$treedata[] = $_treedata->fields;
				$_treedata->moveNext();
			}
		}

		$tree_params = array(
			'treedata' => &$treedata,
			'attrs' => &$attrs,
			'section' => $section
		);

		$plugin_manager->do_hook('tree', $tree_params);

		$this->printTreeXML($treedata, $attrs);
	}

	/** Produce XML data for the browser tree
	 * @param array $treedata A set of records to populate the tree.
	 * @param array $attrs Attributes for tree items
	 *        'text' - the text for the tree node
	 *        'icon' - an icon for node
	 *        'openIcon' - an alternative icon when the node is expanded
	 *        'toolTip' - tool tip text for the node
	 *        'action' - URL to visit when single clicking the node
	 *        'iconAction' - URL to visit when single clicking the icon node
	 *        'branch' - URL for child nodes (tree XML)
	 *        'expand' - the action to return XML for the subtree
	 *        'nodata' - message to display when node has no children
	 */
	function printTreeXML($treedata, $attrs) {
		global $conf, $lang;

		header("Content-Type: text/xml; charset=UTF-8");
		header("Cache-Control: no-cache");

		echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";

		echo "<tree>\n";

		if (count($treedata) > 0) {
			foreach ($treedata as $rec) {

				echo "<tree";
				echo value_xml_attr('text', $attrs['text'], $rec);
				echo value_xml_attr('action', $attrs['action'], $rec);
				echo value_xml_attr('src', $attrs['branch'], $rec);

				$icon = $this->icon(value($attrs['icon'], $rec));
				echo value_xml_attr('icon', $icon, $rec);
				echo value_xml_attr('iconaction', $attrs['iconAction'], $rec);

				if (!empty($attrs['openicon'])) {
					$icon = $this->icon(value($attrs['openIcon'], $rec));
				}
				echo value_xml_attr('openicon', $icon, $rec);

				echo value_xml_attr('tooltip', $attrs['toolTip'], $rec);

				echo " />\n";
			}
		} else {
			$msg = $attrs['nodata'] ?? $lang['strnoobjects'];
			echo "<tree text=\"{$msg}\" onaction=\"tree.getSelected().getParent().reload()\" icon=\"", $this->icon('ObjectNotFound'), "\" />\n";
		}

		echo "</tree>\n";
	}

	/**
	 * @param array $tabs
	 * @return ArrayRecordSet
	 */
	function adjustTabsForTree(&$tabs) {

		foreach ($tabs as $i => $tab) {
			if ((isset($tab['hide']) && $tab['hide'] === true) || (isset($tab['tree']) && $tab['tree'] === false)) {
				unset($tabs[$i]);
			}
		}
		return new ArrayRecordSet($tabs);
	}

	private static function buildIconCache() {
		$cache = [];

		// Themes
		foreach (glob("images/themes/*/*.{svg,png}", GLOB_BRACE) as $file) {
			$parts = explode('/', $file);
			// images/themes/<theme>/<icon>.<ext>
			$theme = $parts[2];
			$icon  = pathinfo($file, PATHINFO_FILENAME);

			$cache['themes'][$theme][$icon] = $file;
		}

		// Plugins
		foreach (glob("plugins/*/images/*.{svg,png}", GLOB_BRACE) as $file) {
			$parts = explode('/', $file);
			// plugins/<plugin>/images/<icon>.<ext>
			$plugin = $parts[1];
			$icon   = pathinfo($file, PATHINFO_FILENAME);

			$cache['plugins'][$plugin][$icon] = $file;
		}

		return $cache;
	}

	/**
	 * @param string|string[] $icon
	 * @return string
	 */
	function icon($icon) {
		global $conf;
		static $cache = null;
		if (!isset($cache)) {
			$cache = self::buildIconCache();
		}
		if (is_string($icon)) {
			// Icon from themes
			return $cache['themes'][$conf['theme']][$icon]
				?? $cache['themes']['default'][$icon]
				?? '';
		} else {
			// Icon from plugins
			return $cache['plugins'][$icon[0]][$icon[1]] ?? '';
		}
	}

	/**
	 * Function to escape command line parameters
	 * @param $str The string to escape
	 * @return The escaped string
	 */
	function escapeShellArg($str) {
		global $data, $lang;

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			// Due to annoying PHP bugs, shell arguments cannot be escaped
			// (command simply fails), so we cannot allow complex objects
			// to be dumped.
			if (preg_match('/^[_.[:alnum:]]+$/', $str))
				return $str;
			else {
				echo $lang['strcannotdumponwindows'];
				exit;
			}
		} else
			return escapeshellarg($str);
	}

	/**
	 * Function to escape command line programs
	 * @param $str The string to escape
	 * @return The escaped string
	 */
	function escapeShellCmd($str) {
		global $data;

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$data->fieldClean($str);
			return '"' . $str . '"';
		} else
			return escapeshellcmd($str);
	}

	/**
	 * Get list of servers' groups if existing in the conf
	 * @return ArrayRecordSet a recordset of servers' groups
	 */
	function getServersGroups($recordset = false, $group_id = false) {
		global $conf, $lang;
		$grps = array();

		if (isset($conf['srv_groups'])) {
			foreach ($conf['srv_groups'] as $i => $group) {
				if (
					(($group_id === false) and (!isset($group['parents']))) /* root */
					or (
						($group_id !== false)
						and isset($group['parents'])
						and in_array($group_id, explode(',',
							preg_replace('/\s/', '', $group['parents'])
						))
					) /* nested group */
				)
					$grps[$i] = array(
						'id' => $i,
						'desc' => $group['desc'],
						'icon' => 'Servers',
						'action' => url('servers.php',
							array(
								'group' => field('id')
							)
						),
						'branch' => url('servers.php',
							array(
								'action' => 'tree',
								'group' => $i
							)
						)
					);
			}

			if ($group_id === false)
				$grps['all'] = array(
					'id' => 'all',
					'desc' => $lang['strallservers'],
					'icon' => 'Servers',
					'action' => url('servers.php',
						array(
							'group' => field('id')
						)
					),
					'branch' => url('servers.php',
						array(
							'action' => 'tree',
							'group' => 'all'
						)
					)
				);
		}

		if ($recordset) {
			return new ArrayRecordSet($grps);
		}

		return $grps;
	}


	/**
	 * Get list of servers
	 * @param bool $recordset return as RecordSet suitable for printTable if true,
	 *                   otherwise just return an array.
	 * @param string $group a group name to filter the returned servers using $conf[srv_groups]
	 */
	function getServers($recordset = false, $group = false) {
		global $conf;

		$logins = isset($_SESSION['webdbLogin']) && is_array($_SESSION['webdbLogin']) ? $_SESSION['webdbLogin'] : array();
		$srvs = array();

		if (($group !== false) and ($group !== 'all'))
			if (isset($conf['srv_groups'][$group]['servers']))
				$group = array_fill_keys(explode(',', preg_replace('/\s/', '',
					$conf['srv_groups'][$group]['servers'])), 1);
			else
				$group = '';

		foreach ($conf['servers'] as $idx => $info) {
			$server_id = $info['host'] . ':' . $info['port'] . ':' . $info['sslmode'];
			if (($group === false)
				or (isset($group[$idx]))
				or ($group === 'all')
			) {
				$server_id = $info['host'] . ':' . $info['port'] . ':' . $info['sslmode'];

				if (isset($logins[$server_id])) $srvs[$server_id] = $logins[$server_id];
				else $srvs[$server_id] = $info;

				$srvs[$server_id]['id'] = $server_id;
				$srvs[$server_id]['action'] = url('redirect.php',
					array(
						'subject' => 'server',
						'server' => field('id')
					)
				);
				if (isset($srvs[$server_id]['username'])) {
					$srvs[$server_id]['icon'] = 'Server';
					$srvs[$server_id]['branch'] = url('all_db.php',
						array(
							'action' => 'tree',
							'subject' => 'server',
							'server' => field('id')
						)
					);
				} else {
					$srvs[$server_id]['icon'] = 'DisconnectedServer';
					$srvs[$server_id]['branch'] = false;
				}
			}
		}

		uasort($srvs, function($a, $b) {
			return strcmp($a['desc'], $b['desc']);
		});

		if ($recordset) {
			return new ArrayRecordSet($srvs);
		}
		return $srvs;
	}

	/**
	 * Validate and retrieve information on a server.
	 * If the parameter isn't supplied then the currently
	 * connected server is returned.
	 * @param string $server_id A server identifier (host:port)
	 * @return array An associative array of server properties
	 */
	function getServerInfo($server_id = null) {
		global $conf, $_reload_browser, $lang;

		if ($server_id === null && isset($_REQUEST['server']))
			$server_id = $_REQUEST['server'];

		// Check for the server in the logged-in list
		if (isset($_SESSION['webdbLogin'][$server_id]))
			return $_SESSION['webdbLogin'][$server_id];

		// Otherwise, look for it in the conf file
		foreach ($conf['servers'] as $idx => $info) {
			if ($server_id == $info['host'] . ':' . $info['port'] . ':' . $info['sslmode']) {
				// Automatically use shared credentials if available
				if (!isset($info['username']) && isset($_SESSION['sharedUsername'])) {
					$info['username'] = $_SESSION['sharedUsername'];
					$info['password'] = $_SESSION['sharedPassword'];
					$_reload_browser = true;
					$this->setServerInfo(null, $info, $server_id);
				}

				return $info;
			}
		}

		if ($server_id === null) {
			return null;
		} else {
			// Unable to find a matching server, are we being hacked?
			echo $lang['strinvalidserverparam'];
			exit;
		}
	}

	/**
	 * Set server information.
	 * @param $key parameter name to set, or null to replace all
	 *             params with the assoc-array in $value.
	 * @param $value the new value, or null to unset the parameter
	 * @param $server_id the server identifier, or null for current
	 *                   server.
	 */
	function setServerInfo($key, $value, $server_id = null) {
		if ($server_id === null && isset($_REQUEST['server']))
			$server_id = $_REQUEST['server'];

		if ($key === null) {
			if ($value === null)
				unset($_SESSION['webdbLogin'][$server_id]);
			else
				$_SESSION['webdbLogin'][$server_id] = $value;
		} else {
			if ($value === null)
				unset($_SESSION['webdbLogin'][$server_id][$key]);
			else
				$_SESSION['webdbLogin'][$server_id][$key] = $value;
		}
	}

	/**
	 * Set the current schema
	 * @param string $schema The schema name
	 * @return 0 on success
	 * @return $data->seSchema() on error
	 */
	function setCurrentSchema($schema) {
		/** @var Postgres $data */
		global $data;
		if ($data->_schema == $schema) {
			return 0;
		}

		$status = $data->setSchema($schema);
		if ($status != 0)
			return $status;

		$_REQUEST['schema'] = $schema;
		$this->setHREF();
		return 0;
	}

	/**
	 * Save the given SQL script in the history of the database and server.
	 * @param string $script the SQL script to save.
	 */
	function saveScriptHistory($script) {
		list($usec, $sec) = explode(' ', microtime());
		$time = ((float)$usec + (float)$sec);
		$_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']]["$time"] = array(
			'query' => $script,
			'paginate' => (!isset($_REQUEST['paginate']) ? 'f' : 't'),
			'queryid' => $time,
		);
	}

	/*
	 * Output dropdown list to select server and
	 * databases form the popups windows.
	 * @param $onchange Javascript action to take when selections change.
	 */
	function printConnection($onchange) {
		global $data, $lang, $misc;

		echo "<table style=\"width: 100%\"><tr><td>\n";
		echo "<label>";
		$this->printHelp($lang['strserver'], 'pg.server');
		echo "</label>";
		echo ": <select name=\"server\" {$onchange}>\n";

		$servers = $this->getServers();
		foreach ($servers as $info) {
			if (empty($info['username'])) continue; // not logged on this server
			echo "<option value=\"", htmlspecialchars($info['id']), "\"",
			((isset($_REQUEST['server']) && $info['id'] == $_REQUEST['server'])) ? ' selected="selected"' : '', ">",
			htmlspecialchars("{$info['desc']} ({$info['id']})"), "</option>\n";
		}
		echo "</select>\n</td><td style=\"text-align: right\">\n";

		// Get the list of all databases
		$databases = $data->getDatabases();

		if ($databases->recordCount() > 0) {

			echo "<label>";
			$this->printHelp($lang['strdatabase'], 'pg.database');
			echo ": <select name=\"database\" {$onchange}>\n";

			//if no database was selected, user should select one
			if (!isset($_REQUEST['database']))
				echo "<option value=\"\">--</option>\n";

			while (!$databases->EOF) {
				$dbname = $databases->fields['datname'];
				echo "<option value=\"", htmlspecialchars($dbname), "\"",
				((isset($_REQUEST['database']) && $dbname == $_REQUEST['database'])) ? ' selected="selected"' : '', ">",
				htmlspecialchars($dbname), "</option>\n";
				$databases->moveNext();
			}
			echo "</select></label>\n";
		} else {
			$server_info = $this->getServerInfo();
			echo "<input type=\"hidden\" name=\"database\" value=\"",
			htmlspecialchars($server_info['defaultdb']), "\" />\n";
		}

		echo "</td></tr></table>\n";
	}

	/**
	 * returns an array representing FKs definition for a table, sorted by fields
	 * or by constraint.
	 * @param $table The table to retrieve FK constraints from
	 * @returns the array of FK definition:
	 *   array(
	 *     'byconstr' => array(
	 *       constrain id => array(
	 *         confrelid => foreign relation oid
	 *         f_schema => foreign schema name
	 *         f_table => foreign table name
	 *         pattnums => array of parent's fields nums
	 *         pattnames => array of parent's fields names
	 *         fattnames => array of foreign attributes names
	 *       )
	 *     ),
	 *     'byfield' => array(
	 *       attribute num => array (constraint id, ...)
	 *     ),
	 *     'code' => HTML/js code to include in the page for auto-completion
	 *   )
	 **/
	function getAutocompleteFKProperties($table) {
		global $data;

		$fksprops = array(
			'byconstr' => array(),
			'byfield' => array(),
			'code' => ''
		);

		$constrs = $data->getConstraintsWithFields($table);

		if (!$constrs->EOF) {
			$conrelid = $constrs->fields['conrelid'];
			while (!$constrs->EOF) {
				if ($constrs->fields['contype'] == 'f') {
					if (!isset($fksprops['byconstr'][$constrs->fields['conid']])) {
						$fksprops['byconstr'][$constrs->fields['conid']] = array(
							'confrelid' => $constrs->fields['confrelid'],
							'f_table' => $constrs->fields['f_table'],
							'f_schema' => $constrs->fields['f_schema'],
							'pattnums' => array(),
							'pattnames' => array(),
							'fattnames' => array()
						);
					}

					$fksprops['byconstr'][$constrs->fields['conid']]['pattnums'][] = $constrs->fields['p_attnum'];
					$fksprops['byconstr'][$constrs->fields['conid']]['pattnames'][] = $constrs->fields['p_field'];
					$fksprops['byconstr'][$constrs->fields['conid']]['fattnames'][] = $constrs->fields['f_field'];

					if (!isset($fksprops['byfield'][$constrs->fields['p_attnum']]))
						$fksprops['byfield'][$constrs->fields['p_attnum']] = array();
					$fksprops['byfield'][$constrs->fields['p_attnum']][] = $constrs->fields['conid'];
				}
				$constrs->moveNext();
			}

			$fksprops['code'] = "<script type=\"text/javascript\">\n";
			$fksprops['code'] .= "var constrs = {};\n";
			foreach ($fksprops['byconstr'] as $conid => $props) {
				$fksprops['code'] .= "constrs.constr_{$conid} = {\n";
				$fksprops['code'] .= 'pattnums: [' . implode(',', $props['pattnums']) . "],\n";
				$fksprops['code'] .= "f_table:'" . addslashes(htmlentities($props['f_table'], ENT_QUOTES, 'UTF-8')) . "',\n";
				$fksprops['code'] .= "f_schema:'" . addslashes(htmlentities($props['f_schema'], ENT_QUOTES, 'UTF-8')) . "',\n";
				$_ = '';
				foreach ($props['pattnames'] as $n) {
					$_ .= ",'" . htmlentities($n, ENT_QUOTES, 'UTF-8') . "'";
				}
				$fksprops['code'] .= 'pattnames: [' . substr($_, 1) . "],\n";

				$_ = '';
				foreach ($props['fattnames'] as $n) {
					$_ .= ",'" . htmlentities($n, ENT_QUOTES, 'UTF-8') . "'";
				}

				$fksprops['code'] .= 'fattnames: [' . substr($_, 1) . "]\n";
				$fksprops['code'] .= "};\n";
			}

			$fksprops['code'] .= "var attrs = {};\n";
			foreach ($fksprops['byfield'] as $attnum => $cstrs) {
				$fksprops['code'] .= "attrs.attr_{$attnum} = [" . implode(',', $fksprops['byfield'][$attnum]) . "];\n";
			}

			$fksprops['code'] .= "var table='" . addslashes(htmlentities($table, ENT_QUOTES, 'UTF-8')) . "';";
			$fksprops['code'] .= "var server='" . htmlentities($_REQUEST['server'], ENT_QUOTES, 'UTF-8') . "';";
			$fksprops['code'] .= "var database='" . addslashes(htmlentities($_REQUEST['database'], ENT_QUOTES, 'UTF-8')) . "';";
			$fksprops['code'] .= "</script>\n";

			$fksprops['code'] .= '<div id="fkbg"></div>';
			$fksprops['code'] .= '<div id="fklist"></div>';
			$fksprops['code'] .= '<script src="js/ac_insert_row.js" type="text/javascript"></script>';
		} else /* we have no foreign keys on this table */
			return false;

		return $fksprops;
	}
}
