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
     * Set HTTP headers for regular file download (non-gzipped).
     * Used for 'download' mode only (show and gzipped modes have dedicated methods).
     *
     * @param string $filename Base filename (without extension)
     * @param string $mime_type MIME type for the output
     * @param string $file_extension File extension (without dot)
     */
    public static function setDownloadHeaders($filename, $mime_type, $file_extension)
    {
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename={$filename}.{$file_extension}");
    }

    /**
     * Begin gzipped stream output using zlib.deflate filter.
     * This provides true streaming compression without buffering the entire dump in memory.
     *
     * @param string $filename Base filename for the download (without extension)
     * @return resource|null The output stream resource, or null on error
     */
    public static function beginGzipStream($filename)
    {
        // Clear any existing output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Disable output buffering to allow direct streaming
        ini_set('output_buffering', 'Off');
        ini_set('zlib.output_compression', 'Off');

        // Set headers for gzipped stream
        header('Content-Type: application/gzip');
        header("Content-Disposition: attachment; filename={$filename}.sql.gz");

        // Open output stream and attach zlib.deflate filter for compression
        // window=31 adds gzip format headers automatically
        $output_stream = fopen('php://output', 'wb');
        if ($output_stream === false) {
            return null;
        }

        $filter = stream_filter_append($output_stream, 'zlib.deflate', STREAM_FILTER_WRITE, ['window' => 31]);
        if ($filter === false) {
            fclose($output_stream);
            return null;
        }

        return $output_stream;
    }

    /**
     * End gzipped stream output.
     * Closes the stream and flushes any remaining data.
     *
     * @param resource $output_stream The stream resource from beginGzipStream()
     */
    public static function endGzipStream($output_stream)
    {
        if (is_resource($output_stream)) {
            fclose($output_stream);
        }
    }
}
