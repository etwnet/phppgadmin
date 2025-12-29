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


    public static function beginZipStream($filename)
    {

        // Clear any existing output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Disable output buffering to allow direct streaming
        ini_set('output_buffering', 'Off');
        ini_set('zlib.output_compression', 'Off');

        // Prepare php://output and ZipStream that will write directly to it.
        // Verify ZipStream is available
        if (!class_exists('\ZipStream\\ZipStream')) {
            return null;
        }

        // Ensure zlib is available for compression (library uses deflate)
        if (!extension_loaded('zlib')) {
            return null;
        }

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return null;
        }

        $options = new ArchiveOptions();
        $options->setOutputStream($out);
        $options->setSendHttpHeaders(true);

        $zip = new ZipStream("{$filename}.zip", $options);

        // Return the ZipStream instance: callers may use addFileFromStream()
        // for streaming external command output (pg_dump) or further wiring.
        return $zip;
    }
}
