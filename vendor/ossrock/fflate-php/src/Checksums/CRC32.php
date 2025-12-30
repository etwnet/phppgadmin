<?php

namespace Ossrock\FflatePhp\Checksums;

/**
 * CRC32 checksum calculator for GZIP
 */
class CRC32
{
    /**
     * CRC32 lookup table
     * @var int[]|null
     */
    private static $table = null;

    /**
     * Initialize CRC32 lookup table
     */
    private static function initTable()
    {
        if (self::$table !== null) {
            return;
        }

        self::$table = [];
        for ($i = 0; $i < 256; ++$i) {
            $c = $i;
            for ($k = 0; $k < 8; ++$k) {
                $c = ($c & 1) ? (0xEDB88320 ^ (($c >> 1) & 0x7FFFFFFF)) : (($c >> 1) & 0x7FFFFFFF);
            }
            self::$table[$i] = $c;
        }
    }

    /**
     * Calculate CRC32 checksum
     *
     * @param string $data Binary data
     * @param int $crc Initial CRC value (default: 0)
     * @return int CRC32 checksum (unsigned 32-bit)
     */
    public static function calculate($data, $crc = 0)
    {
        self::initTable();

        $crc = $crc ^ 0xFFFFFFFF;
        $len = strlen($data);

        for ($i = 0; $i < $len; ++$i) {
            $crc = self::$table[($crc ^ ord($data[$i])) & 0xFF] ^ (($crc >> 8) & 0x00FFFFFF);
        }

        return ($crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
    }

    /**
     * Calculate CRC32 using PHP's built-in function (faster)
     *
     * @param string $data Binary data
     * @param int $crc Initial CRC value (default: 0)
     * @return int CRC32 checksum (unsigned 32-bit)
     */
    public static function hash($data, $crc = 0)
    {
        // PHP's crc32 returns signed int, convert to unsigned
        if ($crc === 0) {
            $result = crc32($data);
        } else {
            // For continuing CRC, we need to use the table-based method
            return self::calculate($data, $crc);
        }

        // Convert signed to unsigned 32-bit
        return $result & 0xFFFFFFFF;
    }
}
