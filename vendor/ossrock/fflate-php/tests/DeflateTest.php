<?php

namespace Ossrock\FflatePhp\Tests;

use Ossrock\FflatePhp\Deflate\Deflate;
use Ossrock\FflatePhp\Inflate\Inflate;
use PHPUnit\Framework\TestCase;

class DeflateTest extends TestCase
{
    public function testDeflateInflateRoundtripFixed()
    {
        $input = str_repeat('Hello, World! This is a test string. ', 200);
        $def = Deflate::deflate($input, 6);
        $out = Inflate::inflate($def);
        $this->assertSame($input, $out);
    }

    public function testDeflateStoredLevel0Roundtrip()
    {
        $input = random_bytes(5000);
        $def = Deflate::deflate($input, 0);
        $out = Inflate::inflate($def);
        $this->assertSame($input, $out);
    }

    public function testMatchesNativeGzinflateWhenAvailable()
    {
        if (!function_exists('gzinflate') || !function_exists('gzdeflate')) {
            $this->markTestSkipped('Native zlib functions not available');
        }

        $input = str_repeat('abcabcabcabcabc', 2000);
        $def = Deflate::deflate($input, 6);
        $nativeOut = gzinflate($def);
        $this->assertSame($input, $nativeOut);

        $nativeDef = gzdeflate($input, 6);
        $ourOut = Inflate::inflate($nativeDef);
        $this->assertSame($input, $ourOut);
    }
}
