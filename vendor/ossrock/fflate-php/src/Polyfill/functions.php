<?php
/**
 * Global polyfill functions for gz* PHP functions
 * These are only loaded if the native functions don't exist
 */

use Ossrock\FflatePhp\Gzip\GzipDecoder;
use Ossrock\FflatePhp\Gzip\GzipEncoder;
use Ossrock\FflatePhp\Inflate\Inflate;
use Ossrock\FflatePhp\Deflate\Deflate;
use Ossrock\FflatePhp\Zlib\ZlibDecoder;
use Ossrock\FflatePhp\Zlib\ZlibEncoder;
use Ossrock\FflatePhp\FflatePhp;


if (!extension_loaded('zlib')) {
    // ext-zlib is not available; define missing constants
    if (!defined('ZLIB_VERSION')) {
        define('ZLIB_VERSION', 'fflate-php');
    }
    if (!defined('ZLIB_VERNUM')) {
        define('ZLIB_VERNUM', 0x12B0);
    }
    if (!defined('FORCE_GZIP')) {
        define('FORCE_GZIP', 31);
    }
    if (!defined('FORCE_DEFLATE')) {
        define('FORCE_DEFLATE', 15);
    }
    if (!defined('ZLIB_ENCODING_RAW')) {
        define('ZLIB_ENCODING_RAW', -15);
    }
    if (!defined('ZLIB_ENCODING_DEFLATE')) {
        define('ZLIB_ENCODING_DEFLATE', 15);
    }
    if (!defined('ZLIB_ENCODING_GZIP')) {
        define('ZLIB_ENCODING_GZIP', 31);
    }
    FflatePhp::registerFilters();
    FflatePhp::registerWrappers();
}

/**
 * @return array{fopenMode: string, direction: 'r'|'w', level: int}|false
 */
function __fflate_php_parse_gzopen_mode($mode)
{
    $mode = (string) $mode;
    if ($mode === '') {
        return false;
    }

    // Extract optional compression level (e.g. wb9)
    $level = 6;
    if (preg_match('/([0-9])/', $mode, $m)) {
        $level = (int) $m[1];
    }

    // Remove digits for fopen mode
    $fopenMode = preg_replace('/[0-9]/', '', $mode);
    if ($fopenMode === null || $fopenMode === '') {
        return false;
    }

    // gzopen supports + but our filter-based approach can't do simultaneous read/write
    if (strpos($fopenMode, '+') !== false) {
        trigger_error('gzopen polyfill does not support + modes', E_USER_WARNING);
        return false;
    }

    // Ensure binary mode
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

    trigger_error('gzopen polyfill: unsupported mode', E_USER_WARNING);
    return false;
}

/**
 * @param mixed $stream
 */
function __fflate_php_stream_id($stream)
{
    if (!is_resource($stream)) {
        return null;
    }
    $id = (int) $stream;
    if ($id <= 0) {
        return null;
    }
    return $id;
}

if (!isset($GLOBALS['__fflate_php_gz_streams']) || !is_array($GLOBALS['__fflate_php_gz_streams'])) {
    $GLOBALS['__fflate_php_gz_streams'] = [];
}

// String APIs: always-available fflate_* prefixed functions, plus gz* wrappers if native absent

/**
 * Decode a gzip compressed string
 *
 * @param string $data Gzip compressed data
 * @param int $length Maximum length (compat)
 * @return string|false
 */
function fflate_gzdecode($data, $length = 0)
{
    return GzipDecoder::decode($data);
}

/**
 * Encode a string with gzip.
 */
function fflate_gzencode($data, $level = -1, $encoding_mode = FORCE_GZIP)
{
    if ($level === -1) {
        $level = 6;
    }
    try {
        return GzipEncoder::encode($data, (int) $level);
    } catch (\Throwable $e) {
        trigger_error('fflate_gzencode failed: ' . $e->getMessage(), E_USER_WARNING);
        return false;
    }
}

/**
 * Compress a string using the DEFLATE algorithm.
 */
function fflate_gzdeflate($data, $level = -1, $encoding = ZLIB_ENCODING_RAW)
{
    if ($level === -1) {
        $level = 6;
    }
    try {
        return Deflate::deflate($data, (int) $level);
    } catch (\Throwable $e) {
        trigger_error('fflate_gzdeflate failed: ' . $e->getMessage(), E_USER_WARNING);
        return false;
    }
}

/**
 * Inflate a deflated string.
 */
function fflate_gzinflate($data, $length = 0)
{
    try {
        return Inflate::inflate($data);
    } catch (\Throwable $e) {
        trigger_error('fflate_gzinflate failed: ' . $e->getMessage(), E_USER_WARNING);
        return false;
    }
}

/**
 * Compress a string with zlib encoding.
 */
function fflate_gzcompress($data, $level = -1, $encoding = ZLIB_ENCODING_DEFLATE)
{
    if ($level === -1) {
        $level = 6;
    }
    try {
        return ZlibEncoder::encode($data, (int) $level);
    } catch (\Throwable $e) {
        trigger_error('fflate_gzcompress failed: ' . $e->getMessage(), E_USER_WARNING);
        return false;
    }
}


/**
 * Uncompress a zlib-compressed string.
 */
function fflate_gzuncompress($data, $length = 0)
{
    return ZlibDecoder::decode($data);
}


// --- Stream/file-handle polyfills (gzopen/gzread/gzwrite/...) ---

// Prefixed versions are ALWAYS defined for testing and explicit usage.

/**
 * Open a gzip (.gz) file using fflate stream filters.
 *
 * @param string $filename
 * @param string $mode
 * @param int $use_include_path
 * @return resource|false
 */
function fflate_gzopen($filename, $mode, $use_include_path = 0)
{
    FflatePhp::registerFilters();

    $parsed = __fflate_php_parse_gzopen_mode($mode);
    if ($parsed === false) {
        return false;
    }

    $fp = fopen($filename, $parsed['fopenMode'], (bool) $use_include_path);
    if ($fp === false) {
        return false;
    }

    if ($parsed['direction'] === 'r') {
        $filter = stream_filter_append($fp, 'fflate.gzip.decode', STREAM_FILTER_READ);
    } else {
        $filter = stream_filter_append($fp, 'fflate.gzip.encode', STREAM_FILTER_WRITE, ['level' => $parsed['level']]);
    }

    $id = __fflate_php_stream_id($fp);
    if ($id !== null) {
        $GLOBALS['__fflate_php_gz_streams'][$id] = [
            'direction' => $parsed['direction'],
            'level' => $parsed['level'],
            'pos' => 0,
            'filter' => $filter,
        ];
    }

    return $fp;
}

/**
 * @param resource $zp
 * @param int $length
 * @return string|false
 */
function fflate_gzread($zp, $length)
{
    $data = fread($zp, $length);
    if (is_string($data)) {
        $id = __fflate_php_stream_id($zp);
        if ($id !== null && isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
            $GLOBALS['__fflate_php_gz_streams'][$id]['pos'] += strlen($data);
        }
    }
    return $data;
}

/**
 * @param resource $zp
 * @param string $string
 * @param int|null $length
 * @return int|false
 */
function fflate_gzwrite($zp, $string, $length = null)
{
    if ($length === null) {
        $written = fwrite($zp, $string);
    } else {
        $written = fwrite($zp, $string, $length);
    }

    if (is_int($written) && $written > 0) {
        $id = __fflate_php_stream_id($zp);
        if ($id !== null && isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
            $GLOBALS['__fflate_php_gz_streams'][$id]['pos'] += $written;
        }
    }

    return $written;
}

/**
 * Alias for fflate_gzwrite().
 *
 * @param resource $zp
 * @param string $string
 * @param int|null $length
 * @return int|false
 */
function fflate_gzputs($zp, $string, $length = null)
{
    return fflate_gzwrite($zp, $string, $length);
}

/**
 * @param resource $zp
 * @return bool
 */
function fflate_gzclose($zp)
{
    $id = __fflate_php_stream_id($zp);
    if ($id !== null) {
        unset($GLOBALS['__fflate_php_gz_streams'][$id]);
    }
    return fclose($zp);
}

/**
 * @param resource $zp
 * @return bool
 */
function fflate_gzeof($zp)
{
    return feof($zp);
}

/**
 * @param resource $zp
 * @return string|false
 */
function fflate_gzgetc($zp)
{
    $c = fgetc($zp);
    if (is_string($c)) {
        $id = __fflate_php_stream_id($zp);
        if ($id !== null && isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
            $GLOBALS['__fflate_php_gz_streams'][$id]['pos'] += 1;
        }
    }
    return $c;
}

/**
 * @param resource $zp
 * @param int $length
 * @return string|false
 */
function fflate_gzgets($zp, $length)
{
    $line = fgets($zp, $length);
    if (is_string($line)) {
        $id = __fflate_php_stream_id($zp);
        if ($id !== null && isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
            $GLOBALS['__fflate_php_gz_streams'][$id]['pos'] += strlen($line);
        }
    }
    return $line;
}

/**
 * @param resource $zp
 * @param int $length
 * @param string $allowable_tags
 * @return string|false
 */
function fflate_gzgetss($zp, $length, $allowable_tags = '')
{
    $line = fflate_gzgets($zp, $length);
    if ($line === false) {
        return false;
    }
    return strip_tags($line, $allowable_tags);
}

/**
 * @param resource $zp
 * @param int $flush_mode Unused
 * @return bool
 */
function fflate_gzflush($zp, $flush_mode = 0)
{
    return fflush($zp);
}

/**
 * @param resource $zp
 * @return int|false
 */
function fflate_gzpassthru($zp)
{
    // Best-effort: fpassthru reads from $zp; update pos by reading the remaining bytes.
    $id = __fflate_php_stream_id($zp);
    $before = null;
    if ($id !== null && isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
        $before = $GLOBALS['__fflate_php_gz_streams'][$id]['pos'];
    }

    $res = fpassthru($zp);

    if (is_int($res) && $res > 0 && $before !== null && $id !== null && isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
        $GLOBALS['__fflate_php_gz_streams'][$id]['pos'] = $before + $res;
    }

    return $res;
}

/**
 * @param string $filename
 * @param int $use_include_path
 * @return array|false
 */
function fflate_gzfile($filename, $use_include_path = 0)
{
    $zp = fflate_gzopen($filename, 'rb', $use_include_path);
    if ($zp === false) {
        return false;
    }
    $lines = [];
    while (!feof($zp)) {
        $line = fgets($zp);
        if ($line === false) {
            break;
        }
        $lines[] = $line;
    }
    fflate_gzclose($zp);
    return $lines;
}

/**
 * Reads a gzip compressed file, outputs it, and returns the number of bytes read.
 *
 * Always available as fflate_readgzfile() for testability.
 *
 * @param string $filename
 * @param int $use_include_path
 * @return int|false
 */
function fflate_readgzfile($filename, $use_include_path = 0)
{
    $zp = fflate_gzopen($filename, 'rb', $use_include_path);
    if ($zp === false) {
        return false;
    }

    $bytes = fflate_gzpassthru($zp);
    fflate_gzclose($zp);
    return $bytes;
}

/**
 * Best-effort uncompressed offset.
 *
 * @param resource $zp
 * @return int
 */
function fflate_gztell($zp)
{
    $id = __fflate_php_stream_id($zp);
    if ($id === null || !isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
        trigger_error('gztell polyfill: unknown stream (use fflate_gzopen)', E_USER_WARNING);
        return -1;
    }
    return (int) $GLOBALS['__fflate_php_gz_streams'][$id]['pos'];
}

/**
 * Best-effort seeking in the uncompressed stream.
 *
 * Supports:
 * - SEEK_SET and SEEK_CUR
 * - forward seek by reading+discarding
 * - backward seek by rewinding the underlying stream (if seekable) + resetting the decode filter
 *
 * @param resource $zp
 * @param int $offset
 * @param int $whence
 * @return int 0 on success, -1 on failure
 */
function fflate_gzseek($zp, $offset, $whence = SEEK_SET)
{
    $id = __fflate_php_stream_id($zp);
    if ($id === null || !isset($GLOBALS['__fflate_php_gz_streams'][$id])) {
        trigger_error('gzseek polyfill: unknown stream (use fflate_gzopen)', E_USER_WARNING);
        return -1;
    }

    $state = $GLOBALS['__fflate_php_gz_streams'][$id];
    if (($state['direction'] ?? 'r') !== 'r') {
        trigger_error('gzseek polyfill only supported for read streams', E_USER_WARNING);
        return -1;
    }

    $cur = (int) ($state['pos'] ?? 0);

    if ($whence === SEEK_SET) {
        $target = (int) $offset;
    } elseif ($whence === SEEK_CUR) {
        $target = $cur + (int) $offset;
    } else {
        trigger_error('gzseek polyfill: SEEK_END not supported', E_USER_WARNING);
        return -1;
    }

    if ($target < 0) {
        return -1;
    }
    if ($target === $cur) {
        return 0;
    }

    // Backward seek: rewind underlying stream and reset decoder filter.
    if ($target < $cur) {
        $meta = stream_get_meta_data($zp);
        if (!isset($meta['seekable']) || $meta['seekable'] !== true) {
            trigger_error('gzseek polyfill: underlying stream not seekable', E_USER_WARNING);
            return -1;
        }

        if (isset($state['filter']) && is_resource($state['filter'])) {
            @stream_filter_remove($state['filter']);
        }

        if (@fseek($zp, 0, SEEK_SET) !== 0) {
            return -1;
        }

        $filter = stream_filter_append($zp, 'fflate.gzip.decode', STREAM_FILTER_READ);
        $GLOBALS['__fflate_php_gz_streams'][$id]['filter'] = $filter;
        $GLOBALS['__fflate_php_gz_streams'][$id]['pos'] = 0;
        $cur = 0;
    }

    // Forward seek by reading+discarding.
    $remaining = $target - $cur;
    while ($remaining > 0) {
        $chunkSize = $remaining > 8192 ? 8192 : $remaining;
        $chunk = fflate_gzread($zp, $chunkSize);
        if (!is_string($chunk) || $chunk === '') {
            return -1;
        }
        $remaining -= strlen($chunk);
    }

    return 0;
}


if (!function_exists('gzdecode')) {
    function gzdecode($data, $length = 0)
    {
        return fflate_gzdecode($data, $length);
    }
}

if (!function_exists('gzencode')) {
    function gzencode($data, $level = -1, $encoding_mode = FORCE_GZIP)
    {
        return fflate_gzencode($data, $level, $encoding_mode);
    }
}

if (!function_exists('gzdeflate')) {
    function gzdeflate($data, $level = -1, $encoding = ZLIB_ENCODING_RAW)
    {
        return fflate_gzdeflate($data, $level, $encoding);
    }
}

if (!function_exists('gzinflate')) {
    function gzinflate($data, $length = 0)
    {
        return fflate_gzinflate($data, $length);
    }
}

if (!function_exists('gzcompress')) {
    function gzcompress($data, $level = -1, $encoding = ZLIB_ENCODING_DEFLATE)
    {
        return fflate_gzcompress($data, $level, $encoding);
    }
}

if (!function_exists('gzuncompress')) {
    function gzuncompress($data, $length = 0)
    {
        return fflate_gzuncompress($data, $length);
    }
}

if (!function_exists('readgzfile')) {
    function readgzfile($filename, $use_include_path = 0)
    {
        return fflate_readgzfile($filename, $use_include_path);
    }
}

if (!function_exists('fflate_gzrewind')) {
    /**
     * @param resource $zp
     * @return bool
     */
    function fflate_gzrewind($zp)
    {
        return fflate_gzseek($zp, 0, SEEK_SET) === 0;
    }
}

if (!function_exists('gzopen')) {
    function gzopen($filename, $mode, $use_include_path = 0)
    {
        return fflate_gzopen($filename, $mode, $use_include_path);
    }
}

if (!function_exists('gzread')) {
    function gzread($zp, $length)
    {
        return fflate_gzread($zp, $length);
    }
}

if (!function_exists('gzwrite')) {
    function gzwrite($zp, $string, $length = null)
    {
        return fflate_gzwrite($zp, $string, $length);
    }
}

if (!function_exists('gzputs')) {
    /**
     * Alias for gzwrite().
     *
     * @param resource $zp
     * @param string $string
     * @param int|null $length
     * @return int|false
     */
    function gzputs($zp, $string, $length = null)
    {
        return fflate_gzputs($zp, $string, $length);
    }
}

if (!function_exists('gzclose')) {
    /**
     * @param resource $zp
     * @return bool
     */
    function gzclose($zp)
    {
        return fflate_gzclose($zp);
    }
}

if (!function_exists('gzeof')) {
    /**
     * @param resource $zp
     * @return bool
     */
    function gzeof($zp)
    {
        return fflate_gzeof($zp);
    }
}

if (!function_exists('gzgetc')) {
    /**
     * @param resource $zp
     * @return string|false
     */
    function gzgetc($zp)
    {
        return fflate_gzgetc($zp);
    }
}

if (!function_exists('gzgets')) {
    /**
     * @param resource $zp
     * @param int $length
     * @return string|false
     */
    function gzgets($zp, $length)
    {
        return fflate_gzgets($zp, $length);
    }
}

if (!function_exists('gzgetss')) {
    /**
     * @param resource $zp
     * @param int $length
     * @param string $allowable_tags
     * @return string|false
     */
    function gzgetss($zp, $length, $allowable_tags = '')
    {
        return fflate_gzgetss($zp, $length, $allowable_tags);
    }
}

if (!function_exists('gzflush')) {
    /**
     * @param resource $zp
     * @param int $flush_mode Unused
     * @return bool
     */
    function gzflush($zp, $flush_mode = 0)
    {
        return fflate_gzflush($zp, $flush_mode);
    }
}

if (!function_exists('gzpassthru')) {
    /**
     * @param resource $zp
     * @return int|false
     */
    function gzpassthru($zp)
    {
        return fflate_gzpassthru($zp);
    }
}

if (!function_exists('gzfile')) {
    /**
     * @param string $filename
     * @param int $use_include_path
     * @return array|false
     */
    function gzfile($filename, $use_include_path = 0)
    {
        return fflate_gzfile($filename, $use_include_path);
    }
}

if (!function_exists('gzseek')) {
    /**
     * Best-effort seeking in the uncompressed stream.
     *
     * @param resource $zp
     * @param int $offset
     * @param int $whence
     * @return int
     */
    function gzseek($zp, $offset, $whence = SEEK_SET)
    {
        return fflate_gzseek($zp, $offset, $whence);
    }
}

if (!function_exists('gztell')) {
    /**
     * Best-effort uncompressed offset.
     *
     * @param resource $zp
     * @return int
     */
    function gztell($zp)
    {
        return fflate_gztell($zp);
    }
}

if (!function_exists('gzrewind')) {
    /**
     * @param resource $zp
     * @return bool
     */
    function gzrewind($zp)
    {
        return fflate_gzrewind($zp);
    }
}
