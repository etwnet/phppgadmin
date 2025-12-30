<?php

namespace Ossrock\FflatePhp\Inflate;

use Ossrock\FflatePhp\FflateException;

/**
 * DEFLATE decompression (inflate) implementation
 */
class Inflate
{
    /**
     * Inflate (decompress) DEFLATE data
     *
     * @param string $data Compressed data
     * @param int $offset Starting offset in bytes (default: 0)
     * @return string Decompressed data
     * @throws FflateException
     */
    public static function inflate($data, $offset = 0)
    {
        $dataLen = strlen($data);

        if ($dataLen === 0) {
            return '';
        }

        $reader = new BitReader($data, $offset * 8);
        $output = '';
        $outputPos = 0;

        // Huffman tables
        $lmap = null;
        $dmap = null;
        $lbits = 0;
        $dbits = 0;
        $final = false;

        while (!$final || $lmap !== null) {
            if ($lmap === null) {
                // Read block header
                $final = $reader->readBitsAdvance(1) === 1;
                $type = $reader->readBitsAdvance(2);

                if ($type === 0) {
                    // Uncompressed block
                    $reader->alignByte();
                    $len = $reader->readByte() | ($reader->readByte() << 8);
                    $nlen = $reader->readByte() | ($reader->readByte() << 8);

                    // Verify complement
                    if (($len ^ $nlen) !== 0xFFFF) {
                        throw FflateException::create(FflateException::INVALID_LENGTH_LITERAL);
                    }

                    $blockData = $reader->readBytes($len);
                    $output .= $blockData;
                    $outputPos += $len;

                    continue;

                } elseif ($type === 1) {
                    // Fixed Huffman
                    $fixed = Huffman::buildFixedTables();
                    $lmap = $fixed['lmap'];
                    $dmap = $fixed['dmap'];
                    $lbits = $fixed['lbits'];
                    $dbits = $fixed['dbits'];

                } elseif ($type === 2) {
                    // Dynamic Huffman
                    $result = self::readDynamicTables($reader);
                    $lmap = $result['lmap'];
                    $dmap = $result['dmap'];
                    $lbits = $result['lbits'];
                    $dbits = $result['dbits'];

                } else {
                    throw FflateException::create(FflateException::INVALID_BLOCK_TYPE);
                }
            }

            // Decompress block
            $lmask = (1 << $lbits) - 1;
            $dmask = (1 << $dbits) - 1;

            while (true) {
                // Read literal/length symbol
                $pos = $reader->getPosition();
                $bits = $reader->readBits16();
                $code = $bits & $lmask;
                $entry = $lmap[$code];

                if ($entry === 0) {
                    throw FflateException::create(FflateException::INVALID_LENGTH_LITERAL);
                }

                $sym = $entry >> 4;
                $codeLen = $entry & 15;

                // Advance position by code length bits
                $reader->setPosition($pos + $codeLen);

                if ($sym < 256) {
                    // Literal byte
                    $output .= chr($sym);
                    ++$outputPos;

                } elseif ($sym === 256) {
                    // End of block
                    $lmap = null;
                    break;

                } else {
                    // Length/distance pair
                    $lengthCode = $sym - 254;

                    if ($sym > 264) {
                        $i = $sym - 257;
                        $extraBits = Tables::$FLEB[$i];
                        $lengthCode = $reader->readBitsAdvance($extraBits) + Tables::$FL[$i];
                    }

                    // Read distance
                    $pos = $reader->getPosition();
                    $bits = $reader->readBits16();
                    $code = $bits & $dmask;
                    $entry = $dmap[$code];

                    if ($entry === 0) {
                        throw FflateException::create(FflateException::INVALID_DISTANCE);
                    }

                    $dsym = $entry >> 4;
                    $dlen = $entry & 15;

                    $reader->setPosition($pos + $dlen);

                    $distance = Tables::$FD[$dsym];

                    if ($dsym > 3) {
                        $extraBits = Tables::$FDEB[$dsym];
                        $distance += $reader->readBitsAdvance($extraBits);
                    }

                    if ($distance > $outputPos) {
                        throw FflateException::create(FflateException::INVALID_DISTANCE);
                    }

                    // Copy from sliding window
                    // For overlapping copies (distance < length), we create RLE by reading
                    // from the pattern as we extend it
                    if ($distance >= $lengthCode) {
                        // Non-overlapping: can copy the whole block at once
                        $output .= substr($output, $outputPos - $distance, $lengthCode);
                        $outputPos += $lengthCode;
                    } else {
                        // Overlapping: must copy byte-by-byte, creating repetition
                        for ($i = 0; $i < $lengthCode; ++$i) {
                            $output .= $output[$outputPos - $distance];
                            ++$outputPos;
                        }
                    }
                }
            }
        }

        return $output;
    }

    /**
     * Read dynamic Huffman tables from stream
     *
     * @param BitReader $reader Bit reader
     * @return array{lmap: array, dmap: array, lbits: int, dbits: int}
     * @throws FflateException
     */
    private static function readDynamicTables($reader)
    {
        // Read header
        $hlit = $reader->readBitsAdvance(5) + 257;   // Number of literal/length codes
        $hdist = $reader->readBitsAdvance(5) + 1;    // Number of distance codes
        $hclen = $reader->readBitsAdvance(4) + 4;    // Number of code length codes

        $totalCodes = $hlit + $hdist;

        // Read code length alphabet
        $codeLengthLengths = array_fill(0, 19, 0);
        for ($i = 0; $i < $hclen; ++$i) {
            $codeLengthLengths[Tables::$CLIM[$i]] = $reader->readBitsAdvance(3);
        }

        // Build code length Huffman table
        $clbits = Huffman::max($codeLengthLengths);
        $clmap = Huffman::buildMap($codeLengthLengths, $clbits, true);
        $clmask = (1 << $clbits) - 1;

        // Decode literal/length and distance code lengths
        $codeLengths = [];
        $i = 0;

        while ($i < $totalCodes) {
            $pos = $reader->getPosition();
            $bits = $reader->readBits16();
            $code = $bits & $clmask;
            $entry = $clmap[$code];

            $sym = $entry >> 4;
            $len = $entry & 15;

            $reader->setPosition($pos + $len);

            if ($sym < 16) {
                // Literal code length
                $codeLengths[$i++] = $sym;

            } else {
                // Repeat previous or zeros
                $repeat = 0;
                $value = 0;

                if ($sym === 16) {
                    // Repeat previous 3-6 times
                    $repeat = 3 + $reader->readBitsAdvance(2);
                    $value = $codeLengths[$i - 1];

                } elseif ($sym === 17) {
                    // Repeat zero 3-10 times
                    $repeat = 3 + $reader->readBitsAdvance(3);

                } elseif ($sym === 18) {
                    // Repeat zero 11-138 times
                    $repeat = 11 + $reader->readBitsAdvance(7);
                }

                while ($repeat-- > 0) {
                    $codeLengths[$i++] = $value;
                }
            }
        }

        // Split into literal/length and distance tables
        $litLengths = array_slice($codeLengths, 0, $hlit);
        $distLengths = array_slice($codeLengths, $hlit);

        // Build Huffman tables
        $lbits = Huffman::max($litLengths);
        $dbits = Huffman::max($distLengths);

        $lmap = Huffman::buildMap($litLengths, $lbits, true);
        $dmap = Huffman::buildMap($distLengths, $dbits, true);

        return [
            'lmap' => $lmap,
            'dmap' => $dmap,
            'lbits' => $lbits,
            'dbits' => $dbits
        ];
    }
}
