<?php

namespace Ossrock\FflatePhp;

/**
 * Main facade class for fflate-php
 */
class FflatePhp
{
    /**
     * @var int Default compression level (0-9)
     */
    private static $defaultLevel = 6;

    /**
     * Check if native zlib extension is available
     *
     * @return bool True if native zlib is available
     */
    public static function isNativeAvailable()
    {
        return extension_loaded('zlib');
    }

    /**
     * Set default compression level
     *
     * @param int $level Compression level (0-9)
     */
    public static function setDefaultLevel($level)
    {
        if ($level < 0 || $level > 9) {
            throw new \InvalidArgumentException('Compression level must be between 0 and 9');
        }
        self::$defaultLevel = $level;
    }

    /**
     * Get default compression level
     *
     * @return int Default compression level
     */
    public static function getDefaultLevel()
    {
        return self::$defaultLevel;
    }

    private static $filterRegistered = false;

    /**
     * Register stream filters
     */
    public static function registerFilters()
    {
        if (self::$filterRegistered) {
            return;
        }
        // Cache available filters once (stream_get_filters can be expensive / return false)
        $filters = stream_get_filters();
        if ($filters === false) {
            $filters = [];
        }
        $have = array_flip($filters);

        if (!isset($have['fflate.gzip.encode'])) {
            @stream_filter_register('fflate.gzip.encode', 'Ossrock\\FflatePhp\\Filter\\GzipEncodeFilter');
        }
        if (!isset($have['fflate.gzip.decode'])) {
            @stream_filter_register('fflate.gzip.decode', 'Ossrock\\FflatePhp\\Filter\\GzipDecodeFilter');
        }
        if (!isset($have['fflate.zlib.encode'])) {
            @stream_filter_register('fflate.zlib.encode', 'Ossrock\\FflatePhp\\Filter\\ZlibEncodeFilter');
        }
        if (!isset($have['fflate.zlib.decode'])) {
            @stream_filter_register('fflate.zlib.decode', 'Ossrock\\FflatePhp\\Filter\\ZlibDecodeFilter');
        }

        // Native-compatible aliases (only relevant when ext-zlib is not available).
        // These names are commonly used with stream_filter_append().
        if (!isset($have['zlib.deflate'])) {
            @stream_filter_register('zlib.deflate', 'Ossrock\\FflatePhp\\Filter\\ZlibEncodeFilter');
        }
        if (!isset($have['zlib.inflate'])) {
            @stream_filter_register('zlib.inflate', 'Ossrock\\FflatePhp\\Filter\\ZlibDecodeFilter');
        }
        self::$filterRegistered = true;
    }

    /**
     * Register stream wrappers.
     *
     * This is primarily intended as a polyfill when ext-zlib is not available.
     * If the wrapper name already exists (e.g. provided by ext-zlib), this is a no-op.
     */
    public static function registerWrappers()
    {
        // Wrapper relies on our filters.
        if (!self::$filterRegistered) {
            self::registerFilters();
        }

        $wrappers = stream_get_wrappers();
        if ($wrappers === false) {
            $wrappers = [];
        }

        $have = array_flip($wrappers);

        if (!isset($have['compress.fflate.zlib'])) {
            @stream_wrapper_register('compress.fflate.zlib', 'Ossrock\\FflatePhp\\Wrapper\\CompressZlibWrapper');
        }

        // "compress.zlib://" is provided by ext-zlib on typical installs.
        // Only register our wrapper when it doesn't already exist.
        if (!isset($have['compress.zlib'])) {
            @stream_wrapper_register('compress.zlib', 'Ossrock\\FflatePhp\\Wrapper\\CompressZlibWrapper');
        }
    }

    /**
     * Get library version
     *
     * @return string Version string
     */
    public static function getVersion()
    {
        return '0.1.0-dev';
    }
}
