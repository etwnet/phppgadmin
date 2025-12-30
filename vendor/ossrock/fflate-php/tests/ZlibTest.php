<?php

namespace Ossrock\FflatePhp\Tests;

use Ossrock\FflatePhp\Zlib\ZlibDecoder;
use Ossrock\FflatePhp\Zlib\ZlibEncoder;
use PHPUnit\Framework\TestCase;

class ZlibTest extends TestCase
{
    public function testEncodeDecodeRoundtrip()
    {
        $input = str_repeat('The quick brown fox jumps over the lazy dog. ', 200);
        $z = ZlibEncoder::encode($input, 6);
        $out = ZlibDecoder::decode($z);
        $this->assertNotFalse($out);
        $this->assertSame($input, $out);
    }

    public function testNativeGzuncompressCanDecodeOurZlibWhenAvailable()
    {
        if (!function_exists('gzuncompress')) {
            $this->markTestSkipped('Native gzuncompress not available');
        }

        $input = str_repeat('xyzxyzxyzxyz', 8000);
        $z = ZlibEncoder::encode($input, 6);
        $out = gzuncompress($z);
        $this->assertSame($input, $out);
    }
}
