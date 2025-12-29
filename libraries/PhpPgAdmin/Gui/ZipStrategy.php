<?php
namespace PhpPgAdmin\Gui;

// Ensure our in-process ZIP writer is available when this strategy is used
require_once __DIR__ . '/ZipStreamer.php';

/**
 * ZipStrategy: in-process ZIP streamer using deflate_init()/deflate_add().
 * Returns a writable resource that the formatter writes to; data is streamed
 * directly to php://output as a single-entry ZIP archive.
 */
class ZipStrategy implements CompressionStrategy
{
    /**
     * Begin ZIP streaming.
     * Returns ['stream' => resource, 'zip' => ZipStreamer, 'id' => string]
     */
    public function begin(string $filename): array
    {
        // Ensure zlib deflate functions are available
        if (!function_exists('deflate_init') || !function_exists('deflate_add')) {
            throw new \RuntimeException('ZIP export requires deflate_init/deflate_add (zlib)');
        }

        // Clear buffers and disable PHP output compression
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ini_set('output_buffering', 'Off');
        ini_set('zlib.output_compression', 'Off');

        // Disable displaying PHP errors into the output stream so HTML error pages
        // cannot corrupt the ZIP archive. Errors should be logged instead.
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
        if (function_exists('error_reporting')) {
            @error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
        }

        // Send basic headers for ZIP download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '.zip"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not open php://output');
        }

        // Create ZipStreamer and register wrapper
        ZipWriterStream::registerWrapper();
        $zip = new ZipStreamer($out);
        $id = uniqid('zip', true);
        ZipWriterStream::addInstance($id, $zip);

        // Start a single file entry inside the archive
        $zip->startFile("{$filename}.sql");

        // Open a writable stream that forwards writes into the ZipStreamer
        $stream = fopen('phppgadminzip://' . $id, 'w');
        if ($stream === false) {
            ZipWriterStream::removeInstance($id);
            throw new \RuntimeException('Could not open internal zip writer stream');
        }

        return [
            'stream' => $stream,
            'zip' => $zip,
            'id' => $id,
            'output' => $out,
        ];
    }

    /**
     * Finish ZIP streaming and cleanup
     */
    public function finish(array $handle): void
    {
        $zip = $handle['zip'] ?? null;
        $stream = $handle['stream'] ?? null;
        $id = $handle['id'] ?? null;
        $out = $handle['output'] ?? null;

        // Close the stream first to ensure all data is written via the wrapper
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($zip instanceof ZipStreamer) {
            // Finish the current file entry (writes data descriptor)
            $zip->finishFile();
            // Write central directory and EOCD
            $zip->finish();
        }

        if ($id !== null) {
            ZipWriterStream::removeInstance($id);
        }

        if (is_resource($out)) {
            fclose($out);
        }
    }
}
