<?php

namespace Ossrock\FflatePhp\Gzip;

use Ossrock\FflatePhp\Checksums\CRC32;
use Ossrock\FflatePhp\Deflate\Deflate;

class GzipEncoder
{
    /**
     * Encode (compress) data as GZIP.
     *
     * @param string $data
     * @param int $level 0-9
     * @return string
     */
    public static function encode($data, $level = 6)
    {
        if ($level < 0 || $level > 9) {
            throw new \InvalidArgumentException('Compression level must be between 0 and 9');
        }

        // Minimal 10-byte header
        // ID1 ID2 CM FLG MTIME(4) XFL OS
        $xfl = 0;
        if ($level === 1) {
            $xfl = 4; // fastest
        } elseif ($level === 9) {
            $xfl = 2; // best
        }

        $header = "\x1f\x8b" . "\x08" . "\x00" . "\x00\x00\x00\x00" . chr($xfl) . "\xff";

        $deflated = Deflate::deflate($data, $level);

        $crc = CRC32::hash($data) & 0xFFFFFFFF;
        $isize = strlen($data) & 0xFFFFFFFF;

        // Footer: CRC32 (LE) + ISIZE (LE)
        $footer = pack('V', $crc) . pack('V', $isize);

        return $header . $deflated . $footer;
    }
}
