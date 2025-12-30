<?php

namespace Ossrock\FflatePhp\Gzip;

use Ossrock\FflatePhp\Inflate\Inflate;
use Ossrock\FflatePhp\Checksums\CRC32;
use Ossrock\FflatePhp\FflateException;

/**
 * GZIP format decoder
 */
class GzipDecoder
{
    /**
     * Decode (decompress) GZIP data
     *
     * @param string $data GZIP compressed data
     * @return string|false Decompressed data or false on error
     */
    public static function decode($data)
    {
        try {
            $len = strlen($data);

            if ($len < 18) {
                trigger_error('invalid gzip data', E_USER_WARNING);
                return false;
            }

            // Parse header
            $headerEnd = self::parseHeader($data);

            if ($headerEnd === false) {
                return false;
            }

            // Extract compressed data (without header and 8-byte footer)
            $compressed = substr($data, $headerEnd, $len - $headerEnd - 8);

            // Decompress
            $output = Inflate::inflate($compressed);

            // Verify CRC32
            $crc = self::readUint32LE($data, $len - 8);
            $calculatedCrc = CRC32::hash($output);

            if ($crc !== $calculatedCrc) {
                trigger_error('gzip CRC32 mismatch', E_USER_WARNING);
                return false;
            }

            // Verify size (modulo 2^32)
            $size = self::readUint32LE($data, $len - 4);
            $actualSize = strlen($output) & 0xFFFFFFFF;

            if ($size !== $actualSize) {
                trigger_error('gzip size mismatch', E_USER_WARNING);
                return false;
            }

            return $output;

        } catch (FflateException $e) {
            trigger_error('gzip decompression failed: ' . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    }

    /**
     * Parse GZIP header
     *
     * @param string $data GZIP data
     * @return int|false Header end position or false on error
     */
    private static function parseHeader($data)
    {
        // Check magic bytes
        if (ord($data[0]) !== 31 || ord($data[1]) !== 139 || ord($data[2]) !== 8) {
            trigger_error('invalid gzip data', E_USER_WARNING);
            return false;
        }

        $flags = ord($data[3]);
        $pos = 10;

        // Skip extra field if present
        if ($flags & 4) {
            if ($pos + 2 > strlen($data)) {
                trigger_error('invalid gzip data', E_USER_WARNING);
                return false;
            }
            $xlen = ord($data[10]) | (ord($data[11]) << 8);
            $pos += 2 + $xlen;
        }

        // Skip filename if present
        if ($flags & 8) {
            while ($pos < strlen($data) && ord($data[$pos]) !== 0) {
                ++$pos;
            }
            ++$pos; // Skip null terminator
        }

        // Skip comment if present
        if ($flags & 16) {
            while ($pos < strlen($data) && ord($data[$pos]) !== 0) {
                ++$pos;
            }
            ++$pos; // Skip null terminator
        }

        // Skip header CRC if present
        if ($flags & 2) {
            $pos += 2;
        }

        if ($pos > strlen($data)) {
            trigger_error('invalid gzip data', E_USER_WARNING);
            return false;
        }

        return $pos;
    }

    /**
     * Read 32-bit unsigned little-endian integer
     *
     * @param string $data Data buffer
     * @param int $offset Offset to read from
     * @return int Unsigned 32-bit value
     */
    private static function readUint32LE($data, $offset)
    {
        return (ord($data[$offset]) |
            (ord($data[$offset + 1]) << 8) |
            (ord($data[$offset + 2]) << 16) |
            (ord($data[$offset + 3]) << 24)) & 0xFFFFFFFF;
    }
}
