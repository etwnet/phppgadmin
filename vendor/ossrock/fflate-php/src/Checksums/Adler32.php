<?php

namespace Ossrock\FflatePhp\Checksums;

/**
 * Adler-32 checksum calculator for Zlib
 */
class Adler32
{
    private const MOD = 65521;

    /**
     * Calculate Adler-32 checksum
     *
     * @param string $data Binary data
     * @param int $adler Initial Adler value (default: 1)
     * @return int Adler-32 checksum (unsigned 32-bit)
     */
    public static function calculate($data, $adler = 1)
    {
        $s1 = $adler & 0xFFFF;
        $s2 = ($adler >> 16) & 0xFFFF;
        $len = strlen($data);

        for ($i = 0; $i < $len; ++$i) {
            $s1 = ($s1 + ord($data[$i])) % self::MOD;
            $s2 = ($s2 + $s1) % self::MOD;
        }

        return ((($s2 << 16) | $s1) & 0xFFFFFFFF);
    }

    /**
     * Calculate Adler-32 using PHP's built-in hash function (faster)
     *
     * @param string $data Binary data
     * @param int $adler Initial Adler value (default: 1)
     * @return int Adler-32 checksum (unsigned 32-bit)
     */
    public static function hash($data, $adler = 1)
    {
        if ($adler !== 1) {
            // For continuing Adler, use manual calculation
            return self::calculate($data, $adler);
        }

        // Use PHP's built-in hash function
        $hash = hash('adler32', $data, true);

        // Unpack as big-endian unsigned 32-bit
        $result = unpack('N', $hash)[1];

        return $result & 0xFFFFFFFF;
    }
}
