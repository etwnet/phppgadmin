<?php

namespace Ossrock\FflatePhp\Zlib;

use Ossrock\FflatePhp\Checksums\Adler32;
use Ossrock\FflatePhp\Inflate\Inflate;
use Ossrock\FflatePhp\FflateException;

class ZlibDecoder
{
    /**
     * Decode (decompress) zlib stream (RFC 1950).
     *
     * @param string $data
     * @return string|false
     */
    public static function decode($data)
    {
        try {
            $len = strlen($data);
            if ($len < 6) {
                trigger_error('invalid zlib data', E_USER_WARNING);
                return false;
            }

            $cmf = ord($data[0]);
            $flg = ord($data[1]);

            // Check CM=8
            if (($cmf & 0x0F) !== 8) {
                trigger_error('invalid zlib data', E_USER_WARNING);
                return false;
            }

            // Check FCHECK
            if (((($cmf << 8) | $flg) % 31) !== 0) {
                trigger_error('invalid zlib data', E_USER_WARNING);
                return false;
            }

            $fdict = ($flg & 0x20) !== 0;
            $pos = 2;
            if ($fdict) {
                // 4-byte dictid
                if ($len < 10) {
                    trigger_error('invalid zlib data', E_USER_WARNING);
                    return false;
                }
                $pos += 4;
            }

            // Last 4 bytes are Adler32
            if ($pos > $len - 4) {
                trigger_error('invalid zlib data', E_USER_WARNING);
                return false;
            }

            $compressed = substr($data, $pos, $len - $pos - 4);
            $out = Inflate::inflate($compressed);

            $adlerExpected = unpack('N', substr($data, $len - 4, 4))[1];
            $adlerActual = Adler32::hash($out) & 0xFFFFFFFF;
            if ($adlerExpected !== $adlerActual) {
                trigger_error('zlib Adler32 mismatch', E_USER_WARNING);
                return false;
            }

            return $out;
        } catch (FflateException $e) {
            trigger_error('zlib decompression failed: ' . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    }
}
