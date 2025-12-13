<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AbstractContext;

class TabsRenderer extends AbstractContext
{

    public function printTabs($tabs, $activeTab): void
    {
        if (is_string($tabs)) {
            $_SESSION['webdbLastTab'][$tabs] = $activeTab;
            $tabs = $this->getNavTabs($tabs);
        }

        if (count($tabs) > 0) {
            $width = (int)(100 / count($tabs)) . '%';
        } else {
            $width = '1';
        }

        echo "<table class=\"tabs\"><tr>\n";
        foreach ($tabs as $tabId => $tab) {
            $active = ($tabId == $activeTab) ? ' active' : '';
            if (isset($tab['hide']) && $tab['hide'] === true) {
                continue;
            }

            $tablink = '<a href="' . htmlentities($this->misc()->getActionUrl($tab, $_REQUEST)) . '">';
            if (isset($tab['icon']) && ($icon = $this->misc()->icon($tab['icon'])) ) {
                $tablink .= "<span class=\"icon\"><img src=\"{$icon}\" alt=\"{$tab['title']}\" /></span>";
            }
            $tablink .= "<span class=\"label\">{$tab['title']}</span></a>";

            echo "<td style=\"width: {$width}\" class=\"tab{$active}\">";
            if (isset($tab['help'])) {
                $this->misc()->printHelp($tablink, $tab['help']);
            } else {
                echo $tablink;
            }
            echo "</td>\n";
        }
        echo "</tr></table>\n";
    }

    public function getNavTabs($section)
    {
        $data = $this->data();
        $lang = $this->lang();
        $conf = $this->conf();
        $pluginManager = $this->pluginManager();

        $hideAdvanced = ($conf['show_advanced'] === false);
        $tabs = array();

        switch ($section) {
        case 'root':
            $tabs = array(
                'intro' => array(
                    'title' => $lang['strintroduction'],
                    'url' => 'intro.php',
                    'icon' => 'Introduction',
                ),
                'servers' => array(
                    'title' => $lang['strservers'],
                    'url' => 'servers.php',
                    'icon' => 'Servers',
                ),
            );
            break;

        case 'server':
            $hideUsers = !$data->isSuperUser();
            $tabs = array(
                'databases' => array(
                    'title' => $lang['strdatabases'],
                    'url' => 'all_db.php',
                    'urlvars' => array('subject' => 'server'),
                    'help' => 'pg.database',
                    'icon' => 'Databases',
                )
            );
            if ($data->hasRoles()) {
                $tabs = array_merge($tabs, array(
                    'roles' => array(
                        'title' => $lang['strroles'],
                        'url' => 'roles.php',
                        'urlvars' => array('subject' => 'server'),
                        'hide' => $hideUsers,
                        'help' => 'pg.role',
                        'icon' => 'Roles',
                    )
                ));
            } else {
                $tabs = array_merge($tabs, array(
                    'users' => array(
                        'title' => $lang['strusers'],
                        'url' => 'users.php',
                        'urlvars' => array('subject' => 'server'),
                        'hide' => $hideUsers,
                        'help' => 'pg.user',
                        'icon' => 'Users',
                    ),
                    'groups' => array(
                        'title' => $lang['strgroups'],
                        'url' => 'groups.php',
                        'urlvars' => array('subject' => 'server'),
                        'hide' => $hideUsers,
                        'help' => 'pg.group',
                        'icon' => 'UserGroups',
                    )
                ));
            }

            $tabs = array_merge($tabs, array(
                'account' => array(
                    'title' => $lang['straccount'],
                    'url' => $data->hasRoles() ? 'roles.php' : 'users.php',
                    'urlvars' => array('subject' => 'server', 'action' => 'account'),
                    'hide' => !$hideUsers,
                    'help' => 'pg.role',
                    'icon' => 'User',
                ),
                'tablespaces' => array(
                    'title' => $lang['strtablespaces'],
                    'url' => 'tablespaces.php',
                    'urlvars' => array('subject' => 'server'),
                    'hide' => (!$data->hasTablespaces()),
                    'help' => 'pg.tablespace',
                    'icon' => 'Tablespaces',
                ),
                'export' => array(
                    'title' => $lang['strexport'],
                    'url' => 'all_db.php',
                    'urlvars' => array('subject' => 'server', 'action' => 'export'),
                    'hide' => (!$this->misc()->isDumpEnabled()),
                    'icon' => 'Export',
                ),
            ));
            break;
        case 'database':
            $tabs = array(
                'schemas' => array(
                    'title' => $lang['strschemas'],
                    'url' => 'schemas.php',
                    'urlvars' => array('subject' => 'database'),
                    'help' => 'pg.schema',
                    'icon' => 'Schemas',
                ),
                'sql' => array(
                    'title' => $lang['strsql'],
                    'url' => 'database.php',
                    'urlvars' => array('subject' => 'database', 'action' => 'sql', 'new' => 1),
                    'help' => 'pg.sql',
                    'tree' => false,
                    'icon' => 'SqlEditor'
                ),
                'find' => array(
                    'title' => $lang['strfind'],
                    'url' => 'database.php',
                    'urlvars' => array('subject' => 'database', 'action' => 'find'),
                    'tree' => false,
                    'icon' => 'Search'
                ),
                'variables' => array(
                    'title' => $lang['strvariables'],
                    'url' => 'database.php',
                    'urlvars' => array('subject' => 'database', 'action' => 'variables'),
                    'help' => 'pg.variable',
                    'tree' => false,
                    'icon' => 'Variables',
                ),
                'processes' => array(
                    'title' => $lang['strprocesses'],
                    'url' => 'database.php',
                    'urlvars' => array('subject' => 'database', 'action' => 'processes'),
                    'help' => 'pg.process',
                    'tree' => false,
                    'icon' => 'Processes',
                ),
                'locks' => array(
                    'title' => $lang['strlocks'],
                    'url' => 'database.php',
                    'urlvars' => array('subject' => 'database', 'action' => 'locks'),
                    'help' => 'pg.locks',
                    'tree' => false,
                    'icon' => 'Key',
                ),
                'admin' => array(
                    'title' => $lang['stradmin'],
                    'url' => 'database.php',
                    'urlvars' => array('subject' => 'database', 'action' => 'admin'),
                    'tree' => false,
                    'icon' => 'Admin',
                ),
                'privileges' => array(
                    'title' => $lang['strprivileges'],
                    'url' => 'privileges.php',
                    'urlvars' => array('subject' => 'database'),
                    'hide' => (!isset($data->privlist['database'])),
                    'help' => 'pg.privilege',
                    'tree' => false,
                    'icon' => 'Privileges',
                ),
                'languages' => array(
                    'title' => $lang['strlanguages'],
                    'url' => 'languages.php',
                    'urlvars' => array('subject' => 'database'),
                    'hide' => $hideAdvanced,
                    'help' => 'pg.language',
                    'icon' => 'Languages',
                ),
                'casts' => array(
                    'title' => $lang['strcasts'],
                    'url' => 'casts.php',
                    'urlvars' => array('subject' => 'database'),
                    'hide' => ($hideAdvanced),
                    'help' => 'pg.cast',
                    'icon' => 'Casts',
                ),
                'export' => array(
                    'title' => $lang['strexport'],
                    'url' => 'database.php',
                    'urlvars' => array('subject' => 'database', 'action' => 'export'),
                    'hide' => (!$this->misc()->isDumpEnabled()),
                    'tree' => false,
                    'icon' => 'Export',
                ),
            );
            break;

        case 'schema':
            $tabs = array(
                'tables' => array(
                    'title' => $lang['strtables'],
                    'url' => 'tables.php',
                    'urlvars' => array('subject' => 'schema'),
                    'help' => 'pg.table',
                    'icon' => 'Tables',
                ),
                'views' => array(
                    'title' => $lang['strviews'],
                    'url' => 'views.php',
                    'urlvars' => array('subject' => 'schema'),
                    'help' => 'pg.view',
                    'icon' => 'Views',
                ),
                'sequences' => array(
                    'title' => $lang['strsequences'],
                    'url' => 'sequences.php',
                    'urlvars' => array('subject' => 'schema'),
                    'help' => 'pg.sequence',
                    'icon' => 'Sequences',
                ),
                'functions' => array(
                    'title' => $lang['strfunctions'],
                    'url' => 'functions.php',
                    'urlvars' => array('subject' => 'schema'),
                    'help' => 'pg.function',
                    'icon' => 'Functions',
                ),
                'fulltext' => array(
                    'title' => $lang['strfulltext'],
                    'url' => 'fulltext.php',
                    'urlvars' => array('subject' => 'schema'),
                    'help' => 'pg.fts',
                    'tree' => true,
                    'icon' => 'Fts',
                ),
                'domains' => array(
                    'title' => $lang['strdomains'],
                    'url' => 'domains.php',
                    'urlvars' => array('subject' => 'schema'),
                    'help' => 'pg.domain',
                    'icon' => 'Domains',
                ),
                'aggregates' => array(
                    'title' => $lang['straggregates'],
                    'url' => 'aggregates.php',
                    'urlvars' => array('subject' => 'schema'),
                    'hide' => $hideAdvanced,
                    'help' => 'pg.aggregate',
                    'icon' => 'Aggregates',
                ),
                'types' => array(
                    'title' => $lang['strtypes'],
                    'url' => 'types.php',
                    'urlvars' => array('subject' => 'schema'),
                    'hide' => $hideAdvanced,
                    'help' => 'pg.type',
                    'icon' => 'Types',
                ),
                'operators' => array(
                    'title' => $lang['stroperators'],
                    'url' => 'operators.php',
                    'urlvars' => array('subject' => 'schema'),
                    'hide' => $hideAdvanced,
                    'help' => 'pg.operator',
                    'icon' => 'Operators',
                ),
                'opclasses' => array(
                    'title' => $lang['stropclasses'],
                    'url' => 'opclasses.php',
                    'urlvars' => array('subject' => 'schema'),
                    'hide' => $hideAdvanced,
                    'help' => 'pg.opclass',
                    'icon' => 'OperatorClasses',
                ),
                'conversions' => array(
                    'title' => $lang['strconversions'],
                    'url' => 'conversions.php',
                    'urlvars' => array('subject' => 'schema'),
                    'hide' => $hideAdvanced,
                    'help' => 'pg.conversion',
                    'icon' => 'Conversions',
                ),
                'privileges' => array(
                    'title' => $lang['strprivileges'],
                    'url' => 'privileges.php',
                    'urlvars' => array('subject' => 'schema'),
                    'help' => 'pg.privilege',
                    'tree' => false,
                    'icon' => 'Privileges',
                ),
                'export' => array(
                    'title' => $lang['strexport'],
                    'url' => 'schemas.php',
                    'urlvars' => array('subject' => 'schema', 'action' => 'export'),
                    'hide' => (!$this->misc()->isDumpEnabled()),
                    'tree' => false,
                    'icon' => 'Export',
                ),
            );
            if (!$data->hasFTS()) {
                unset($tabs['fulltext']);
            }
            break;

        case 'table':
            $tabs = array(
                'columns' => array(
                    'title' => $lang['strcolumns'],
                    'url' => 'tblproperties.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'icon' => 'Columns',
                    'branch' => true,
                ),
                'browse' => array(
                    'title' => $lang['strbrowse'],
                    'icon' => 'Table',
                    'url' => 'display.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'return' => 'table',
                ),
                'select' => array(
                    'title' => $lang['strselect'],
                    'icon' => 'Search',
                    'url' => 'tables.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table'), 'action' => 'confselectrows',),
                    'help' => 'pg.sql.select',
                ),
                'insert' => array(
                    'title' => $lang['strinsert'],
                    'url' => 'display.php',
                    'urlvars' => array(
                        'action' => 'confinsertrow',
                        'subject' => 'table',
                        'table' => field('table'),
                    ),
                    'help' => 'pg.sql.insert',
                    'icon' => 'Operator'
                ),
                'indexes' => array(
                    'title' => $lang['strindexes'],
                    'url' => 'indexes.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'help' => 'pg.index',
                    'icon' => 'Indexes',
                    'branch' => true,
                ),
                'constraints' => array(
                    'title' => $lang['strconstraints'],
                    'url' => 'constraints.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'help' => 'pg.constraint',
                    'icon' => 'Constraints',
                    'branch' => true,
                ),
                'triggers' => array(
                    'title' => $lang['strtriggers'],
                    'url' => 'triggers.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'help' => 'pg.trigger',
                    'icon' => 'Triggers',
                    'branch' => true,
                ),
                'rules' => array(
                    'title' => $lang['strrules'],
                    'url' => 'rules.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'help' => 'pg.rule',
                    'icon' => 'Rules',
                    'branch' => true,
                ),
                'admin' => array(
                    'title' => $lang['stradmin'],
                    'url' => 'tables.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table'), 'action' => 'admin'),
                    'icon' => 'Admin',
                ),
                'info' => array(
                    'title' => $lang['strinfo'],
                    'url' => 'info.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'icon' => 'Statistics',
                ),
                'privileges' => array(
                    'title' => $lang['strprivileges'],
                    'url' => 'privileges.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table')),
                    'help' => 'pg.privilege',
                    'icon' => 'Privileges',
                ),
                'import' => array(
                    'title' => $lang['strimport'],
                    'url' => 'tblproperties.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table'), 'action' => 'import'),
                    'icon' => 'Import',
                    'hide' => false,
                ),
                'export' => array(
                    'title' => $lang['strexport'],
                    'url' => 'tblproperties.php',
                    'urlvars' => array('subject' => 'table', 'table' => field('table'), 'action' => 'export'),
                    'icon' => 'Export',
                    'hide' => false,
                ),
            );
            break;

        case 'view':
            $tabs = array(
                'columns' => array(
                    'title' => $lang['strcolumns'],
                    'url' => 'viewproperties.php',
                    'urlvars' => array('subject' => 'view', 'view' => field('view')),
                    'icon' => 'Columns',
                    'branch' => true,
                ),
                'browse' => array(
                    'title' => $lang['strbrowse'],
                    'icon' => 'Columns',
                    'url' => 'display.php',
                    'urlvars' => array(
                        'action' => 'confselectrows',
                        'return' => 'schema',
                        'subject' => 'view',
                        'view' => field('view')
                    ),
                ),
                'select' => array(
                    'title' => $lang['strselect'],
                    'icon' => 'Search',
                    'url' => 'views.php',
                    'urlvars' => array('action' => 'confselectrows', 'view' => field('view'),),
                    'help' => 'pg.sql.select',
                ),
                'definition' => array(
                    'title' => $lang['strdefinition'],
                    'url' => 'viewproperties.php',
                    'urlvars' => array('subject' => 'view', 'view' => field('view'), 'action' => 'definition'),
                    'icon' => 'Definition'
                ),
                'rules' => array(
                    'title' => $lang['strrules'],
                    'url' => 'rules.php',
                    'urlvars' => array('subject' => 'view', 'view' => field('view')),
                    'help' => 'pg.rule',
                    'icon' => 'Rules',
                    'branch' => true,
                ),
                'privileges' => array(
                    'title' => $lang['strprivileges'],
                    'url' => 'privileges.php',
                    'urlvars' => array('subject' => 'view', 'view' => field('view')),
                    'help' => 'pg.privilege',
                    'icon' => 'Privileges',
                ),
                'export' => array(
                    'title' => $lang['strexport'],
                    'url' => 'viewproperties.php',
                    'urlvars' => array('subject' => 'view', 'view' => field('view'), 'action' => 'export'),
                    'icon' => 'Export',
                    'hide' => false,
                ),
            );
            break;

        case 'function':
            $tabs = array(
                'definition' => array(
                    'title' => $lang['strdefinition'],
                    'url' => 'functions.php',
                    'urlvars' => array(
                        'subject' => 'function',
                        'function' => field('function'),
                        'function_oid' => field('function_oid'),
                        'action' => 'properties',
                    ),
                    'icon' => 'Definition',
                ),
                'privileges' => array(
                    'title' => $lang['strprivileges'],
                    'url' => 'privileges.php',
                    'urlvars' => array(
                        'subject' => 'function',
                        'function' => field('function'),
                        'function_oid' => field('function_oid'),
                    ),
                    'icon' => 'Privileges',
                ),
            );
            break;

        case 'aggregate':
            $tabs = array(
                'definition' => array(
                    'title' => $lang['strdefinition'],
                    'url' => 'aggregates.php',
                    'urlvars' => array(
                        'subject' => 'aggregate',
                        'aggrname' => field('aggrname'),
                        'aggrtype' => field('aggrtype'),
                        'action' => 'properties',
                    ),
                    'icon' => 'Definition',
                ),
            );
            break;

        case 'role':
            $tabs = array(
                'definition' => array(
                    'title' => $lang['strdefinition'],
                    'url' => 'roles.php',
                    'urlvars' => array(
                        'subject' => 'role',
                        'rolename' => field('rolename'),
                        'action' => 'properties',
                    ),
                    'icon' => 'Definition',
                ),
            );
            break;

        case 'popup':
            $tabs = array(
                'sql' => array(
                    'title' => $lang['strsql'],
                    'url' => 'sqledit.php',
                    'urlvars' => array('subject' => 'schema', 'action' => 'sql'),
                    'help' => 'pg.sql',
                    'icon' => 'SqlEditor',
                ),
                'find' => array(
                    'title' => $lang['strfind'],
                    'url' => 'sqledit.php',
                    'urlvars' => array('subject' => 'schema', 'action' => 'find'),
                    'icon' => 'Search',
                ),
            );
            break;

        case 'column':
            $tabs = array(
                'properties' => array(
                    'title' => $lang['strcolprop'],
                    'url' => 'colproperties.php',
                    'urlvars' => array(
                        'subject' => 'column',
                        'table' => field('table'),
                        'column' => field('column')
                    ),
                    'icon' => 'Column'
                ),
                'privileges' => array(
                    'title' => $lang['strprivileges'],
                    'url' => 'privileges.php',
                    'urlvars' => array(
                        'subject' => 'column',
                        'table' => field('table'),
                        'column' => field('column')
                    ),
                    'help' => 'pg.privilege',
                    'icon' => 'Privileges',
                )
            );
            break;

        case 'fulltext':
            $tabs = array(
                'ftsconfigs' => array(
                    'title' => $lang['strftstabconfigs'],
                    'url' => 'fulltext.php',
                    'urlvars' => array('subject' => 'schema'),
                    'hide' => !$data->hasFTS(),
                    'help' => 'pg.ftscfg',
                    'tree' => true,
                    'icon' => 'FtsCfg',
                ),
                'ftsdicts' => array(
                    'title' => $lang['strftstabdicts'],
                    'url' => 'fulltext.php',
                    'urlvars' => array('subject' => 'schema', 'action' => 'viewdicts'),
                    'hide' => !$data->hasFTS(),
                    'help' => 'pg.ftsdict',
                    'tree' => true,
                    'icon' => 'FtsDict',
                ),
                'ftsparsers' => array(
                    'title' => $lang['strftstabparsers'],
                    'url' => 'fulltext.php',
                    'urlvars' => array('subject' => 'schema', 'action' => 'viewparsers'),
                    'hide' => !$data->hasFTS(),
                    'help' => 'pg.ftsparser',
                    'tree' => true,
                    'icon' => 'FtsParser',
                ),
            );
            break;
        }

        if ($pluginManager) {
            $pluginFunctionsParameters = array(
                'tabs' => &$tabs,
                'section' => $section
            );
            $pluginManager->do_hook('tabs', $pluginFunctionsParameters);
        }

        return $tabs;
    }

    public function getLastTabURL($section)
    {
        $tabs = $this->getNavTabs($section);

        if (isset($_SESSION['webdbLastTab'][$section]) && isset($tabs[$_SESSION['webdbLastTab'][$section]])) {
            $tab = $tabs[$_SESSION['webdbLastTab'][$section]];
        } else {
            $tab = reset($tabs);
        }

        return isset($tab['url']) ? $tab : null;
    }

}
