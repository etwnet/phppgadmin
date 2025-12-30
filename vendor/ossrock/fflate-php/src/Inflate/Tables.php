<?php

namespace Ossrock\FflatePhp\Inflate;

/**
 * Static lookup tables for DEFLATE decompression
 */
class Tables
{
    /**
     * Fixed length extra bits
     * @var int[]
     */
    public static $FLEB = [0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0, 0, 0, 0];

    /**
     * Fixed distance extra bits
     * @var int[]
     */
    public static $FDEB = [0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13, 0, 0];

    /**
     * Code length index map
     * @var int[]
     */
    public static $CLIM = [16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15];

    /**
     * Fixed length base values
     * @var int[]
     */
    public static $FL;

    /**
     * Reverse fixed length map
     * @var int[]
     */
    public static $REVFL;

    /**
     * Fixed distance base values
     * @var int[]
     */
    public static $FD;

    /**
     * Reverse fixed distance map
     * @var int[]
     */
    public static $REVFD;

    /**
     * Bit reversal lookup table for 15-bit values
     * @var int[]
     */
    public static $REV;

    /**
     * Fixed literal/length tree (288 entries)
     * @var int[]
     */
    public static $FLT;

    /**
     * Fixed distance tree (32 entries)
     * @var int[]
     */
    public static $FDT;

    /**
     * Initialize static tables
     */
    public static function init()
    {
        if (self::$FL !== null) {
            return;
        }

        // Generate base and reverse tables for lengths
        // DEFLATE length codes are 3-based (code 257 => length 3)
        $result = self::generateBaseReverse(self::$FLEB, 3);
        self::$FL = $result['base'];
        self::$REVFL = $result['reverse'];
        self::$FL[28] = 258;
        self::$REVFL[258] = 28;

        // Generate base and reverse tables for distances
        // DEFLATE distance codes are 1-based (code 0 => distance 1)
        $result = self::generateBaseReverse(self::$FDEB, 1);
        self::$FD = $result['base'];
        self::$REVFD = $result['reverse'];

        // Generate bit reversal table
        self::$REV = [];
        for ($i = 0; $i < 32768; ++$i) {
            $x = (($i & 0xAAAA) >> 1) | (($i & 0x5555) << 1);
            $x = (($x & 0xCCCC) >> 2) | (($x & 0x3333) << 2);
            $x = (($x & 0xF0F0) >> 4) | (($x & 0x0F0F) << 4);
            self::$REV[$i] = ((($x & 0xFF00) >> 8) | (($x & 0x00FF) << 8)) >> 1;
        }

        // Fixed literal/length tree
        self::$FLT = array_merge(
            array_fill(0, 144, 8),
            array_fill(144, 112, 9),
            array_fill(256, 24, 7),
            array_fill(280, 8, 8)
        );

        // Fixed distance tree
        self::$FDT = array_fill(0, 32, 5);
    }

    /**
     * Generate base and reverse index maps from extra bits
     *
     * @param int[] $eb Extra bits array
     * @param int $start Starting value
     * @return array{base: int[], reverse: int[]}
     */
    private static function generateBaseReverse(array $eb, $start)
    {
        $base = [];
        $base[0] = $start;

        for ($i = 1; $i < 31; ++$i) {
            $start += 1 << $eb[$i - 1];
            $base[$i] = $start;
        }

        $reverse = array_fill(0, $base[30], 0);

        for ($i = 1; $i < 30; ++$i) {
            for ($j = $base[$i]; $j < $base[$i + 1]; ++$j) {
                $reverse[$j] = (($j - $base[$i]) << 5) | $i;
            }
        }

        return ['base' => $base, 'reverse' => $reverse];
    }
}

// Initialize tables on load
Tables::init();
