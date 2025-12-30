<?php

namespace Ossrock\FflatePhp\Zlib;

use Ossrock\FflatePhp\Checksums\Adler32;
use Ossrock\FflatePhp\Deflate\Deflate;

class ZlibEncoder
{
    /**
     * Encode (compress) data as zlib stream (RFC 1950).
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

        // CMF: CM=8 (deflate), CINFO=7 (32K window)
        $cmf = 0x78;

        // FLG presets (no dictionary). These already satisfy FCHECK.
        // Common zlib headers: 78 01, 78 5E, 78 9C, 78 DA
        if ($level <= 1) {
            $flg = 0x01;
        } elseif ($level <= 5) {
            $flg = 0x5E;
        } elseif ($level === 6) {
            $flg = 0x9C;
        } else {
            $flg = 0xDA;
        }

        $header = chr($cmf) . chr($flg);

        $deflated = Deflate::deflate($data, $level);

        $adler = Adler32::hash($data) & 0xFFFFFFFF;
        // Adler32 is big-endian in zlib
        $footer = pack('N', $adler);

        return $header . $deflated . $footer;
    }
}
