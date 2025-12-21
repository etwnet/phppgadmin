<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Misc;

class ConnectionSelector extends AbstractContext
{

    public function printConnection($onchange)
    {
        $pg = $this->postgres();
        $lang = $this->lang();

        echo "<table style=\"width: 100%\"><tr><td><div class=\"flex-row\">\n";
        echo "<label for=\"connection-server\">";
        $this->misc()->printHelp($lang['strserver'], 'pg.server');
        echo ":&nbsp;</label>";
        echo "<select id=\"connection-server\" data-use-in-url=\"1\" name=\"server\" {$onchange}>\n";

        $servers = $this->misc()->getServers();
        foreach ($servers as $info) {
            if (empty($info['username'])) {
                continue;
            }
            echo "<option value=\"", htmlspecialchars($info['id']), "\"", ((isset($_REQUEST['server']) && $info['id'] == $_REQUEST['server'])) ? ' selected="selected"' : '', ">",
                htmlspecialchars("{$info['desc']} ({$info['id']})"), "</option>\n";
        }
        echo "</select>\n</div>\n</td>\n<td>\n<div class=\"flex-row justify-content-end\">\n";

        $databases = (new DatabaseActions($pg))->getDatabases();

        if ($databases->recordCount() > 0) {
            echo "<label for=\"connection-database\">&nbsp;&nbsp;&nbsp;";
            $this->misc()->printHelp($lang['strdatabase'], 'pg.database');
            echo ":&nbsp;</label>";
            echo "<select id=\"connection-database\" data-use-in-url=\"1\" name=\"database\" {$onchange}>\n";

            if (!isset($_REQUEST['database'])) {
                echo "<option value=\"\">--</option>\n";
            }

            while (!$databases->EOF) {
                $dbname = $databases->fields['datname'];
                echo "<option value=\"", htmlspecialchars($dbname), "\"", ((isset($_REQUEST['database']) && $dbname == $_REQUEST['database'])) ? ' selected="selected"' : '', ">",
                    htmlspecialchars($dbname), "</option>\n";
                $databases->moveNext();
            }
            echo "</select>\n";
        } else {
            $server_info = $this->misc()->getServerInfo();
            echo "<input type=\"hidden\" name=\"database\" value=\"",
                htmlspecialchars($server_info['defaultdb']), "\" />\n";
        }

        echo "</td></div></tr></table>\n";
    }
}
