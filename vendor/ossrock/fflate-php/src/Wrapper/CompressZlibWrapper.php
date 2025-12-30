<?php

namespace Ossrock\FflatePhp\Wrapper;

use Exception;
use Ossrock\FflatePhp\FflatePhp;

/**
 * A minimal userland replacement for PHP's ext-zlib stream wrapper "compress.zlib://".
 *
 * This wrapper provides transparent gzip read/write by attaching the existing
 * fflate stream filters to the underlying file handle.
 */
class CompressZlibWrapper
{
    /** @var resource|null */
    private $fp;

    /** @var resource|null stream filter resource */
    private $filter;

    /** @var 'r'|'w'|null */
    private $direction;

    /** @var string|null */
    private $openedPath;

    /**
     * Stream context (set by PHP).
     *
     * @var resource|null
     */
    public $context;

    /**
     * @param string $path
     * @param int $flags
     * @return array|false
     */
    public function url_stat($path, $flags)
    {
        $realPath = self::pathFromUri($path);

        $quiet = (bool) ($flags & STREAM_URL_STAT_QUIET);
        if ($quiet) {
            return @stat($realPath);
        }

        return stat($realPath);
    }

    /**
     * @return array|false
     */
    public function stream_stat()
    {
        if (!is_resource($this->fp)) {
            return false;
        }

        return fstat($this->fp);
    }

    /**
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string|null $opened_path
     * @return bool
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $parsed = self::parseMode($mode);
        if ($parsed === false) {
            return false;
        }

        $useIncludePath = (bool) ($options & STREAM_USE_PATH);

        $realPath = self::pathFromUri($path);
        $opened_path = $realPath;
        $this->openedPath = $realPath;

        $ctx = is_resource($this->context) ? $this->context : null;

        $fp = $ctx === null
            ? fopen($realPath, $parsed['fopenMode'], $useIncludePath)
            : fopen($realPath, $parsed['fopenMode'], $useIncludePath, $ctx);

        if ($fp === false) {
            return false;
        }

        $this->fp = $fp;
        $this->filter = null;
        $this->direction = $parsed['direction'];

        // Ensure filters are available; wrapper relies on them.
        FflatePhp::registerFilters();

        if ($parsed['direction'] === 'r') {
            $this->filter = stream_filter_append($this->fp, 'fflate.gzip.decode', STREAM_FILTER_READ);
        } else {
            $level = $this->resolveLevel($parsed['level']);
            $this->filter = stream_filter_append($this->fp, 'fflate.gzip.encode', STREAM_FILTER_WRITE, ['level' => $level]);
        }

        return true;
    }

    /**
     * @param int $count
     * @return string|false
     */
    public function stream_read($count)
    {
        if (!is_resource($this->fp)) {
            return false;
        }

        return fread($this->fp, $count);
    }

    /**
     * @param string $data
     * @return int|false
     */
    public function stream_write($data)
    {
        if (!is_resource($this->fp)) {
            return false;
        }

        return fwrite($this->fp, $data);
    }

    /**
     * @return bool
     */
    public function stream_eof()
    {
        if (!is_resource($this->fp)) {
            return true;
        }

        return feof($this->fp);
    }

    /**
     * @return int|false
     */
    public function stream_tell()
    {
        if (!is_resource($this->fp)) {
            return false;
        }

        return ftell($this->fp);
    }

    /**
     * @param int $offset
     * @param int $whence
     * @return bool
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        if (!is_resource($this->fp)) {
            return false;
        }

        // NOTE: seeking through a decode filter is best-effort; for the one-liner
        // use case (copy/streaming) this is typically not relied upon.
        return fseek($this->fp, $offset, $whence) === 0;
    }

    /**
     * @return bool
     */
    public function stream_flush()
    {
        if (!is_resource($this->fp)) {
            return false;
        }

        return fflush($this->fp);
    }

    public function stream_close(): void
    {
        if (is_resource($this->fp)) {
            // PHP 7.2 has an edge case where write filters may not receive the
            // closing flush when used behind a userland stream wrapper.
            // Explicitly removing the filter forces it to flush its final bytes
            // (gzip footer) before we close the underlying stream.
            if ($this->direction === 'w' && is_resource($this->filter)) {
                @stream_filter_remove($this->filter);
            }

            fclose($this->fp);
        }

        $this->fp = null;
        $this->filter = null;
        $this->direction = null;
    }

    /**
     * @param int $option
     * @param int $arg1
     * @param int|null $arg2
     * @return bool
     */
    public function stream_set_option($option, $arg1, $arg2 = null)
    {
        if (!is_resource($this->fp)) {
            return false;
        }

        if ($option === STREAM_OPTION_BLOCKING) {
            return stream_set_blocking($this->fp, (bool) $arg1);
        }

        if ($option === STREAM_OPTION_READ_TIMEOUT && is_array($arg2)) {
            $sec = isset($arg2['sec']) ? (int) $arg2['sec'] : 0;
            $usec = isset($arg2['usec']) ? (int) $arg2['usec'] : 0;
            return stream_set_timeout($this->fp, $sec, $usec);
        }

        if ($option === STREAM_OPTION_WRITE_BUFFER) {
            return stream_set_write_buffer($this->fp, (int) $arg1) === 0;
        }

        return false;
    }

    /**
     * @param string $path
     * @return bool
     */
    public function unlink($path)
    {
        return unlink(self::pathFromUri($path));
    }

    /**
     * @param string $uri
     */
    private static function pathFromUri($uri): string
    {
        $uri = (string) $uri;

        if (substr_compare($uri, 'compress.zlib://', 0, 16) === 0) {
            $path = substr($uri, 16);
        } elseif (substr_compare($uri, 'compress.fflate.zlib://', 0, 22) === 0) {
            $path = substr($uri, 22);
        } else {
            $path = $uri;
        }

        $path = rawurldecode($path);

        // Handle "/C:/..." (or any number of leading slashes) on Windows
        $path = preg_replace('#^/+([A-Za-z]:[/\\\])#', '$1', $path) ?? $path;

        return $path;
    }

    /**
     * @param string $mode
     * @return array{fopenMode: string, direction: 'r'|'w', level: int}|false
     */
    private static function parseMode($mode)
    {
        $mode = (string) $mode;
        if ($mode === '') {
            return false;
        }

        // Extract an optional compression level digit, similar to gzopen's modes.
        $level = FflatePhp::getDefaultLevel();
        if (preg_match('/([0-9])/', $mode, $m)) {
            $level = (int) $m[1];
        }

        // Remove digits so the underlying fopen mode stays valid.
        $fopenMode = preg_replace('/[0-9]/', '', $mode);
        if ($fopenMode === null || $fopenMode === '') {
            return false;
        }

        if (strpos($fopenMode, '+') !== false) {
            trigger_error('compress.zlib wrapper does not support + modes', E_USER_WARNING);
            return false;
        }

        if (strpos($fopenMode, 'b') === false) {
            $fopenMode .= 'b';
        }

        $c = $fopenMode[0];
        if ($c === 'r') {
            return ['fopenMode' => $fopenMode, 'direction' => 'r', 'level' => $level];
        }
        if ($c === 'w' || $c === 'a' || $c === 'x' || $c === 'c') {
            return ['fopenMode' => $fopenMode, 'direction' => 'w', 'level' => $level];
        }

        trigger_error('compress.zlib wrapper: unsupported mode', E_USER_WARNING);
        return false;
    }

    private function resolveLevel(int $fallback): int
    {
        $level = $fallback;

        // Prefer stream context options (compatible-ish with ext-zlib) if provided.
        if (is_resource($this->context)) {
            $opts = stream_context_get_options($this->context);

            if (isset($opts['zlib']['level'])) {
                $level = (int) $opts['zlib']['level'];
            } elseif (isset($opts['fflate']['level'])) {
                $level = (int) $opts['fflate']['level'];
            }
        }

        if ($level < 0) {
            $level = 0;
        }
        if ($level > 9) {
            $level = 9;
        }

        return $level;
    }
}
