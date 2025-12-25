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
        $misc->printHeader("Export", null);
        $misc->printBody();
        $misc->printTrail('database');

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
    public static function finishHtmlOutput()
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

    /**
     * Set appropriate HTTP headers for different export output modes.
     *
     * @param string $output_mode 'show', 'download', or 'gzipped'
     * @param string $filename Base filename (without extension)
     * @param string $mime_type MIME type for the output
     * @param string $file_extension File extension (without dot)
     */
    public static function setOutputHeaders($output_mode, $filename, $mime_type, $file_extension)
    {
        if ($output_mode === 'download') {
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename={$filename}.{$file_extension}");
        } elseif ($output_mode === 'gzipped') {
            header('Content-Type: application/gzip');
            header('Content-Encoding: gzip');
            header("Content-Disposition: attachment; filename={$filename}.{$file_extension}.gz");
            ob_start('ob_gzhandler');
        } else {
            // 'show' mode - display in browser
            header("Content-Type: {$mime_type}; charset=utf-8");
        }
    }
}
