<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Misc;

class LayoutRenderer extends AbstractContext
{
    private $misc;

    public function __construct(Misc $misc)
    {
        $this->misc = $misc;
    }

    public function printHeader($title = '', $scripts = '')
    {
        global $appName, $_no_html_frame;
        $lang = $this->lang();
        $conf = $this->conf();
        $plugin_manager = $this->pluginManager();

        // capture ajax/json requests
        if (!empty($_REQUEST['ajax'])) {
            $this->misc->ajaxRequest = true;
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
            $this->misc->frameContentRequest = true;
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
                 background-image: url("<?= $this->misc->icon('I') ?> ");
             }
             .calendar-icon-bg {
                 background-image: url("<?= $this->misc->icon('Calendar') ?> ");
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

    public function printBody()
    {
        global $_no_html_frame;
        $lang = $this->lang();

        if (!empty($_no_html_frame) || $this->misc->ajaxRequest) {
            return;
        }
        $this->misc->hasFrameset = true;
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

    public function printFooter()
    {
        global $_reload_browser, $_reload_tree;
        global $_no_bottom_link, $_no_html_frame;
        $lang = $this->lang();

        if ($this->misc->ajaxRequest) {
            return;
        }

        if (isset($_reload_browser)) {
            $this->printReload(false);
        } elseif (isset($_reload_tree)) {
            $this->printReload(true);
        }

        if ($this->misc->hasFrameset || $this->misc->frameContentRequest) {
            echo "<hr>\n";
            echo "<div class=\"clearfix bottom-footer\">\n";
            echo "<a href=\"\" target=\"_blank\" class=\"new_window\" title=\"", htmlspecialchars($lang['strnewwindow']), "\"><img src=\"", $this->misc->icon("NewWindow"), "\" ></a>";
            echo "</div>\n";
        }

        if (!isset($_no_bottom_link)) {
            echo "<a href=\"#\" class=\"bottom_link\">⇱</a>";
        }

        if (!empty($_no_html_frame)) {
            return;
        }
        if ($this->misc->hasFrameset) {
            echo "</div>\n"; // close #content div
            echo "</div>\n"; // close #content-container div
            echo "</div>\n"; // close #frameset div
        }
        echo "</body>\n";
        echo "</html>\n";
    }

    public function printBrowser()
    {
        global $appName;
        $lang = $this->lang();
        ?>
        <div class="logo">
            <a href="index.php">
                <?= htmlspecialchars($appName) ?>
            </a>
        </div>
        <div class="refreshTree">
            <a href="#" onclick="writeTree()"><img class="icon" src="<?= $this->misc->icon('Refresh'); ?>" alt="<?= $lang['strrefresh']; ?>" title="<?= $lang['strrefresh']; ?>"/></a>
        </div>
        <div id="wfxt-container"></div>
        <script>

            webFXTreeConfig.rootIcon = "<?= $this->misc->icon('Servers') ?>";
            webFXTreeConfig.openRootIcon = "<?= $this->misc->icon('Servers') ?>";
            webFXTreeConfig.folderIcon = "";
            webFXTreeConfig.openFolderIcon = "";
            webFXTreeConfig.fileIcon = "";
            webFXTreeConfig.iIcon = "<?= $this->misc->icon('I') ?>";
            webFXTreeConfig.lIcon = "<?= $this->misc->icon('L') ?>";
            webFXTreeConfig.lMinusIcon = "<?= $this->misc->icon('Lminus') ?>";
            webFXTreeConfig.lPlusIcon = "<?= $this->misc->icon('Lplus') ?>";
            webFXTreeConfig.tIcon = "<?= $this->misc->icon('T') ?>";
            webFXTreeConfig.tMinusIcon = "<?= $this->misc->icon('Tminus') ?>";
            webFXTreeConfig.tPlusIcon = "<?= $this->misc->icon('Tplus') ?>";
            webFXTreeConfig.blankIcon = "<?= $this->misc->icon('blank') ?>";
            webFXTreeConfig.loadingIcon = "<?= $this->misc->icon('Loading') ?>";
            webFXTreeConfig.loadingText = "<?= $lang['strloading'] ?>";
            webFXTreeConfig.errorIcon = "<?= $this->misc->icon('ObjectNotFound') ?>";
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

    public function printReload($tree)
    {
        echo "<script>\n";
        if ($tree) {
            echo "\twriteTree();\n";
        } else {
            echo "\twindow.location.replace(\"index.php\");\n";
        }
        echo "</script>\n";
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
				$tag .= 'href="' . htmlentities($this->misc->getActionUrl($value, $link['fields'])) . '" ';
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
	 * Print out the page heading and help link
	 * @param string $title Title, already escaped
	 * @param $help (optional) The identifier for the help link
	 */
	function printTitle($title, $help = null) {
		echo "<h2>";
		$this->printHelp($title, $help);
		echo "</h2>\n";
	}

	/**
	 * Displays link to the context help.
	 * @param string $str - the string that the context help is related to (already escaped)
	 * @param string $help - help section identifier
	 */
	function printHelp($str, $help) {
		$lang = $this->lang();

		echo $str;
		if ($help) {
			echo "<a class=\"help\" href=\"";
			echo htmlspecialchars("help.php?help=" . urlencode($help) . "&server=" . urlencode($_REQUEST['server']));
			echo "\" title=\"{$lang['strhelp']}\" target=\"phppgadminhelp\">{$lang['strhelpicon']}</a>";
		}
	}

	/**
	 * Do multi-page navigation.  Displays the prev, next and page options.
	 * @param int $page - the page currently viewed
	 * @param int $pages - the maximum number of pages
	 * @param array $gets -  the parameters to include in the link to the wanted page
	 */
	function printPageNavigation($page, $pages, $gets) {
		$conf = $this->conf();
		$lang = $this->lang();
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
	 * Render a value into HTML using formatting rules specified
	 * by a type name and parameters.
	 *
	 * @param string $str The string to change
	 *
	 * @param string $type Field type (optional), this may be an internal PostgreSQL type, or:
	 *         yesno    - same as bool, but renders as 'Yes' or 'No'.
	 *         pre      - render in a <pre> block.
	 *         nbsp     - replace all spaces with &nbsp;'s
	 *         verbatim - render exactly as supplied, no escaping what-so-ever.
	 *         callback - render using a callback function supplied in the 'function' param.
	 *
	 * @param array $params Type parameters (optional), known parameters:
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
		$lang = $this->lang();
		$conf = $this->conf();

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
			$out = $this->postgres()->escapeBytea($str);
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
}
