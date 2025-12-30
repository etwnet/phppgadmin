<?php

namespace Ossrock\FflatePhp\Tests;

use Ossrock\FflatePhp\FflatePhp;
use Ossrock\FflatePhp\Gzip\GzipDecoder;
use Ossrock\FflatePhp\Gzip\GzipEncoder;
use PHPUnit\Framework\TestCase;

class StreamWrapperTest extends TestCase
{
    public function testCompressZlibWrapperCopyPlainToGzAndBack()
    {
        // Ensure our wrapper is registered.
        FflatePhp::registerWrappers();

        $wrappers = stream_get_wrappers();
        $this->assertIsArray($wrappers);
        $this->assertContains('compress.fflate.zlib', $wrappers);

        $input = str_repeat("hello wrapper\n", 5000);

        $plainPath = tempnam(sys_get_temp_dir(), 'fflate-w-plain-');
        $this->assertNotFalse($plainPath);
        file_put_contents($plainPath, $input);

        $gzPath = tempnam(sys_get_temp_dir(), 'fflate-w-gz-');
        $this->assertNotFalse($gzPath);
        @unlink($gzPath);
        $gzPath .= '.gz';

        try {
            $ok = copy($plainPath, 'compress.fflate.zlib://' . $gzPath);
            $this->assertTrue($ok);

            $gzBytes = file_get_contents($gzPath);
            $this->assertIsString($gzBytes);
            $decoded = GzipDecoder::decode($gzBytes);
            $this->assertSame($input, $decoded);

            $outPath = tempnam(sys_get_temp_dir(), 'fflate-w-out-');
            $this->assertNotFalse($outPath);

            $ok2 = copy('compress.fflate.zlib://' . $gzPath, $outPath);
            $this->assertTrue($ok2);

            $out = file_get_contents($outPath);
            $this->assertSame($input, $out);

            @unlink($outPath);
        } finally {
            @unlink($plainPath);
            @unlink($gzPath);
        }
    }

    public function testCompressZlibWrapperFileGetContentsReadsDecodedBytes()
    {
        FflatePhp::registerWrappers();

        $input = str_repeat('content-', 2000);
        $gz = GzipEncoder::encode($input, 6);

        $gzPath = tempnam(sys_get_temp_dir(), 'fflate-w-gz-in-');
        $this->assertNotFalse($gzPath);
        file_put_contents($gzPath, $gz);

        try {
            $out = file_get_contents('compress.fflate.zlib://' . $gzPath);
            $this->assertSame($input, $out);
        } finally {
            @unlink($gzPath);
        }
    }
}
