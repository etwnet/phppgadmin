<?php

namespace Ossrock\FflatePhp\Inflate;

use SplFixedArray;

/**
 * Huffman tree builder and decoder for DEFLATE
 */
class Huffman
{
    /**
     * Build Huffman lookup table from code lengths
     *
     * @param int[] $codeLengths Array of code lengths for each symbol
     * @param int $maxBits Maximum bit length
     * @param bool $reverse Whether to build reverse lookup table (for decoding)
     * @return array Huffman lookup table
     */
    public static function buildMap(array $codeLengths, $maxBits, $reverse = false)
    {
        $symbolCount = count($codeLengths);

        // Count number of codes for each bit length
        $lengthCounts = array_fill(0, $maxBits, 0);
        for ($i = 0; $i < $symbolCount; ++$i) {
            if ($codeLengths[$i]) {
                ++$lengthCounts[$codeLengths[$i] - 1];
            }
        }

        // Calculate first code for each bit length
        $firstCodes = array_fill(0, $maxBits, 0);
        for ($i = 1; $i < $maxBits; ++$i) {
            $firstCodes[$i] = ($firstCodes[$i - 1] + $lengthCounts[$i - 1]) << 1;
        }

        if ($reverse) {
            // Build reverse lookup table for decoding
            // This allows quick symbol lookup from bit patterns
            $tableSize = 1 << $maxBits;
            $table = new SplFixedArray($tableSize);
            for ($i = 0; $i < $tableSize; ++$i) {
                $table[$i] = 0;
            }

            $rvb = 15 - $maxBits; // Bits to remove for reverser

            for ($i = 0; $i < $symbolCount; ++$i) {
                if ($codeLengths[$i]) {
                    // Encode both symbol and bit length
                    $sv = ($i << 4) | $codeLengths[$i];

                    // Free bits
                    $freeBits = $maxBits - $codeLengths[$i];

                    // Start value
                    $v = $firstCodes[$codeLengths[$i] - 1]++ << $freeBits;

                    // End value
                    $m = $v | ((1 << $freeBits) - 1);

                    // Fill all bit patterns that start with this code
                    for (; $v <= $m; ++$v) {
                        $table[(Tables::$REV[$v] >> $rvb)] = $sv;
                    }
                }
            }

            // Convert to regular array for easier use
            $result = [];
            for ($i = 0; $i < $tableSize; ++$i) {
                $result[$i] = $table[$i];
            }
            return $result;

        } else {
            // Build forward lookup table for encoding
            $codes = array_fill(0, $symbolCount, 0);

            for ($i = 0; $i < $symbolCount; ++$i) {
                if ($codeLengths[$i]) {
                    $codes[$i] = Tables::$REV[$firstCodes[$codeLengths[$i] - 1]++] >> (15 - $codeLengths[$i]);
                }
            }

            return $codes;
        }
    }

    /**
     * Decode a symbol from the bit stream using Huffman table
     *
     * @param BitReader $reader Bit reader
     * @param array $huffmanMap Huffman lookup table
     * @param int $maxBits Maximum bits to read
     * @return array{symbol: int, bits: int} Decoded symbol and bits consumed
     */
    public static function decode($reader, array $huffmanMap, $maxBits)
    {
        // Read enough bits for lookup
        $bits = $reader->readBits16();
        $mask = (1 << $maxBits) - 1;
        $code = $bits & $mask;

        // Lookup in table
        $entry = $huffmanMap[$code];

        // Extract symbol and bit length
        $symbol = $entry >> 4;
        $length = $entry & 15;

        return ['symbol' => $symbol, 'bits' => $length];
    }

    /**
     * Find maximum value in an array
     *
     * @param int[] $array Input array
     * @return int Maximum value
     */
    public static function max(array $array)
    {
        $max = $array[0];
        $count = count($array);
        for ($i = 1; $i < $count; ++$i) {
            if ($array[$i] > $max) {
                $max = $array[$i];
            }
        }
        return $max;
    }

    /**
     * Build fixed Huffman tables (used for type 1 blocks)
     *
     * @return array{lmap: array, dmap: array, lbits: int, dbits: int}
     */
    public static function buildFixedTables()
    {
        static $cache = null;

        if ($cache === null) {
            $lmap = self::buildMap(Tables::$FLT, 9, true);
            $dmap = self::buildMap(Tables::$FDT, 5, true);

            $cache = [
                'lmap' => $lmap,
                'dmap' => $dmap,
                'lbits' => 9,
                'dbits' => 5
            ];
        }

        return $cache;
    }
}
