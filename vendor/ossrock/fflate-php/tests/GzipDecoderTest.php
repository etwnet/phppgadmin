<?php

namespace Ossrock\FflatePhp\Tests;

use PHPUnit\Framework\TestCase;
use Ossrock\FflatePhp\Gzip\GzipDecoder;

class GzipDecoderTest extends TestCase
{
    public function testDecodeSimpleString()
    {
        // Only run if native gzencode is available for creating test data
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('Native gzencode not available');
        }

        $original = 'Hello, World!';
        $compressed = gzencode($original);

        $decompressed = GzipDecoder::decode($compressed);

        $this->assertNotFalse($decompressed);
        $this->assertEquals($original, $decompressed);
    }

    public function testDecodeLargerText()
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('Native gzencode not available');
        }

        $original = str_repeat('This is a test string with repeated content. ', 100);
        $compressed = gzencode($original);

        $decompressed = GzipDecoder::decode($compressed);

        $this->assertNotFalse($decompressed);
        $this->assertEquals($original, $decompressed);
    }

    public function testDecodeInvalidData()
    {
        $this->expectWarning();
        $this->expectWarningMessage('invalid gzip data');
        $result = GzipDecoder::decode('invalid gzip data');
        $this->assertFalse($result);
    }

    public function testDecodeEmptyData()
    {
        $this->expectWarning();
        $this->expectWarningMessage('invalid gzip data');
        $result = GzipDecoder::decode('');
        $this->assertFalse($result);
    }
}
