<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;

/**
 * Unified export output rendering helper.
 * Provides consistent "show in browser" HTML UI for both database and query exports.
 * Eliminates code duplication between dbexport.php and dataexport.php.
 */
class ExportOutputRenderer
{
    /**
     * Start HTML output for "show in browser" mode.
     * Renders header, navigation, and opens textarea container.
     *
     * @param string|null $exe_path Optional path to external dump utility (e.g., pg_dump)
     * @param string|null $version Optional version of the utility
     */
    public static function beginHtmlOutput($exe_path = null, $version = null)
    {
        AppContainer::setSkipHtmlFrame(false);
        $misc = AppContainer::getMisc();
        $subject = $_REQUEST['subject'] ?? 'server';
        $misc->printHeader("Export", null);
        $misc->printBody();
        $misc->printTrail($subject);
        $misc->printTabs($subject, 'export');

        ?>
        <style>
            .export-controls {
                margin-bottom: 15px;
            }

            .export-controls a {
                margin-right: 15px;
                padding: 5px 10px;
                background: #f0f0f0;
                border: 1px solid #ccc;
                text-decoration: none;
                border-radius: 3px;
            }

            .export-controls a:hover {
                background: #e0e0e0;
            }
        </style>
        <div class="export-controls">
            <a href="javascript:history.back()">← Back</a>
            <a href="javascript:location.reload()">↻ Reload</a>
        </div>
        <?php
        echo "<textarea class=\"dbexport\" readonly>";
        if ($exe_path && $version) {
            echo "-- Dumping with " . htmlspecialchars($exe_path) . " version " . $version . "\n\n";
        }
    }

    /**
     * End HTML output for "show in browser" mode.
     * Closes textarea and renders footer controls.
     */
    public static function endHtmlOutput()
    {
        echo "</textarea>\n";
        ?>
        <div class="export-controls" style="margin-top: 15px;">
            <a href="javascript:history.back()">← Back</a>
            <a href="javascript:location.reload()">↻ Reload</a>
        </div>
        <?php
        $misc = AppContainer::getMisc();
        $misc->printFooter();
    }


}
