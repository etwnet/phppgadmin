<?php

namespace Ossrock\FflatePhp\Tests;

use Ossrock\FflatePhp\Gzip\GzipDecoder;
use Ossrock\FflatePhp\Gzip\GzipEncoder;
use PHPUnit\Framework\TestCase;

class GzipEncoderTest extends TestCase
{
    public function testEncodeDecodeRoundtrip()
    {
        $input = str_repeat('This is a test string with repeated content. ', 100);
        $gz = GzipEncoder::encode($input, 6);
        $out = GzipDecoder::decode($gz);
        $this->assertNotFalse($out);
        $this->assertSame($input, $out);
    }

    public function testNativeGzdecodeCanDecodeOurGzipWhenAvailable()
    {
        if (!function_exists('gzdecode')) {
            $this->markTestSkipped('Native gzdecode not available');
        }

        $input = str_repeat('abc123', 5000);
        $gz = GzipEncoder::encode($input, 6);
        $out = gzdecode($gz);
        $this->assertSame($input, $out);
    }
}
