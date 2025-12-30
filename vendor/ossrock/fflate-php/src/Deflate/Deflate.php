<?php

namespace Ossrock\FflatePhp\Deflate;

use Ossrock\FflatePhp\Inflate\Huffman as InflateHuffman;
use Ossrock\FflatePhp\Inflate\Tables;

class Deflate
{
    /**
     * Compress to raw DEFLATE (RFC 1951) stream.
     *
     * Levels:
     * - 0: stored blocks (no compression)
     * - 1-9: LZ77 + fixed Huffman (valid DEFLATE; not dynamic yet)
     *
     * @param string $data
     * @param int $level 0-9
     * @return string
     */
    public static function deflate($data, $level = 6)
    {
        if ($level < 0 || $level > 9) {
            throw new \InvalidArgumentException('Compression level must be between 0 and 9');
        }

        $len = strlen($data);
        if ($len === 0) {
            // Emit empty stored block with BFINAL=1
            $bw = new BitWriter(16);
            self::writeStoredBlocks($bw, $data, true);
            return $bw->getBuffer();
        }

        if ($level === 0) {
            $bw = new BitWriter($len + 16);
            self::writeStoredBlocks($bw, $data, true);
            return $bw->getBuffer();
        }

        return self::deflateFixed($data, $level, true);
    }

    /**
     * Compress to a single fixed-Huffman block.
     *
     * @param string $data
     * @param int $level
     * @param bool $final
     * @return string
     */
    public static function deflateFixed($data, $level = 6, $final = true)
    {
        Tables::init();

        $litLenLens = Tables::$FLT;
        $distLens = Tables::$FDT;

        $litLenCodes = InflateHuffman::buildMap($litLenLens, 9, false);
        $distCodes = InflateHuffman::buildMap($distLens, 5, false);

        $bw = new BitWriter(strlen($data) + 64);
        self::writeFixedBlock($bw, $data, $level, $final);
        return $bw->getBuffer();
    }

    /**
     * Write one fixed-Huffman DEFLATE block into an existing BitWriter.
     *
     * @param BitWriter $bw
     * @param string $data
     * @param int $level
     * @param bool $final
     */
    public static function writeFixedBlock(BitWriter $bw, $data, $level = 6, $final = false)
    {
        Tables::init();

        $litLenLens = Tables::$FLT;
        $distLens = Tables::$FDT;

        $litLenCodes = InflateHuffman::buildMap($litLenLens, 9, false);
        $distCodes = InflateHuffman::buildMap($distLens, 5, false);

        // Block header: BFINAL + BTYPE=01 (fixed)
        $bw->writeBits($final ? 1 : 0, 1);
        $bw->writeBits(1, 2);

        $maxChain = 16 + ($level * 16);
        $niceLen = 8 + ($level * 16);

        self::encodeLz77Fixed($bw, $data, $litLenCodes, $litLenLens, $distCodes, $distLens, $maxChain, $niceLen);

        // End-of-block
        $bw->writeBits($litLenCodes[256], $litLenLens[256]);
    }

    /**
     * Stored (uncompressed) blocks; supports splitting into 65535 chunks.
     *
     * @param BitWriter $bw
     * @param string $data
     * @param bool $final
     */
    public static function writeStoredBlocks(BitWriter $bw, $data, $final)
    {
        $pos = 0;
        $len = strlen($data);

        while ($pos < $len || ($len === 0 && $pos === 0)) {
            $chunkLen = min(0xFFFF, $len - $pos);
            $isFinal = ($pos + $chunkLen) >= $len;
            if ($len === 0) {
                $chunkLen = 0;
                $isFinal = true;
            }

            // Header
            $bw->writeBits(($final && $isFinal) ? 1 : 0, 1);
            $bw->writeBits(0, 2);

            // Align to next byte boundary
            $bw->alignByte();

            // LEN and NLEN (16-bit little endian)
            $nlen = ($chunkLen ^ 0xFFFF) & 0xFFFF;
            $bw->writeByte($chunkLen & 0xFF);
            $bw->writeByte(($chunkLen >> 8) & 0xFF);
            $bw->writeByte($nlen & 0xFF);
            $bw->writeByte(($nlen >> 8) & 0xFF);

            if ($chunkLen > 0) {
                $bw->writeBytes(substr($data, $pos, $chunkLen));
            }

            $pos += $chunkLen;

            if ($len === 0) {
                break;
            }
        }
    }

    /**
     * Encode data as LZ77 tokens with fixed Huffman coding.
     *
     * @param BitWriter $bw
     * @param string $data
     * @param int[] $litLenCodes
     * @param int[] $litLenLens
     * @param int[] $distCodes
     * @param int[] $distLens
     * @param int $maxChain
     * @param int $niceLen
     */
    private static function encodeLz77Fixed(BitWriter $bw, $data, array $litLenCodes, array $litLenLens, array $distCodes, array $distLens, $maxChain, $niceLen)
    {
        $len = strlen($data);
        if ($len === 0) {
            return;
        }

        $winMask = 0x7FFF; // 32768-1
        $hashSize = 1 << 15;
        $head = array_fill(0, $hashSize, -1);
        $prev = array_fill(0, $len, -1);

        $i = 0;
        while ($i < $len) {
            $bestLen = 0;
            $bestDist = 0;

            if ($i + 3 <= $len) {
                $b0 = ord($data[$i]);
                $b1 = ord($data[$i + 1]);
                $b2 = ord($data[$i + 2]);
                $h = (($b0 << 8) ^ ($b1 << 4) ^ $b2) & ($hashSize - 1);

                $curHead = $head[$h];
                $prev[$i] = $curHead;
                $head[$h] = $i;

                $chain = 0;
                $cand = $curHead;
                $maxLen = min(258, $len - $i);

                while ($cand !== -1 && ($i - $cand) <= 32768 && $chain < $maxChain) {
                    if ($data[$cand] === $data[$i] && $data[$cand + 1] === $data[$i + 1] && $data[$cand + 2] === $data[$i + 2]) {
                        $ml = 3;
                        while ($ml < $maxLen && $data[$cand + $ml] === $data[$i + $ml]) {
                            $ml++;
                        }
                        if ($ml > $bestLen) {
                            $bestLen = $ml;
                            $bestDist = $i - $cand;
                            if ($ml >= $niceLen) {
                                break;
                            }
                        }
                    }

                    $cand = $prev[$cand];
                    $chain++;
                }
            }

            if ($bestLen >= 3) {
                self::writeLengthDistance($bw, $bestLen, $bestDist, $litLenCodes, $litLenLens, $distCodes, $distLens);

                // Insert the intermediate positions into the hash chain
                $end = $i + $bestLen;
                $i++;
                while ($i < $end) {
                    if ($i + 2 < $len) {
                        $b0 = ord($data[$i]);
                        $b1 = ord($data[$i + 1]);
                        $b2 = ord($data[$i + 2]);
                        $h = (($b0 << 8) ^ ($b1 << 4) ^ $b2) & ($hashSize - 1);
                        $prev[$i] = $head[$h];
                        $head[$h] = $i;
                    }
                    $i++;
                }
            } else {
                $sym = ord($data[$i]);
                $bw->writeBits($litLenCodes[$sym], $litLenLens[$sym]);
                $i++;
            }
        }
    }

    /**
     * Write a length/distance pair using fixed Huffman tables.
     *
     * @param BitWriter $bw
     * @param int $length
     * @param int $distance
     * @param int[] $litLenCodes
     * @param int[] $litLenLens
     * @param int[] $distCodes
     * @param int[] $distLens
     */
    private static function writeLengthDistance(BitWriter $bw, $length, $distance, array $litLenCodes, array $litLenLens, array $distCodes, array $distLens)
    {
        if ($length < 3 || $length > 258) {
            throw new \InvalidArgumentException('Invalid match length');
        }
        if ($distance < 1 || $distance > 32768) {
            throw new \InvalidArgumentException('Invalid match distance');
        }

        $rev = Tables::$REVFL[$length];
        $li = $rev & 31;
        $lextra = $rev >> 5;
        $lsym = 257 + $li;

        $bw->writeBits($litLenCodes[$lsym], $litLenLens[$lsym]);
        $leb = Tables::$FLEB[$li];
        if ($leb) {
            $bw->writeBits($lextra, $leb);
        }

        $drev = Tables::$REVFD[$distance];
        $dsym = $drev & 31;
        $dextra = $drev >> 5;

        $bw->writeBits($distCodes[$dsym], $distLens[$dsym]);
        $deb = Tables::$FDEB[$dsym];
        if ($deb) {
            $bw->writeBits($dextra, $deb);
        }
    }
}
