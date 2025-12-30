<?php

namespace Ossrock\FflatePhp\Tests;

use Ossrock\FflatePhp\FflatePhp;
use Ossrock\FflatePhp\Gzip\GzipDecoder;
use Ossrock\FflatePhp\Gzip\GzipEncoder;
use Ossrock\FflatePhp\Zlib\ZlibDecoder;
use Ossrock\FflatePhp\Zlib\ZlibEncoder;
use PHPUnit\Framework\TestCase;

class StreamFilterTest extends TestCase
{
    public function setUp(): void
    {
        FflatePhp::registerFilters();
    }

    public function testGzipEncodeFilter()
    {
        $input = str_repeat('filter test ', 5000);

        $path = tempnam(sys_get_temp_dir(), 'fflate-gz-');
        $fp = fopen($path, 'wb');
        stream_filter_append($fp, 'fflate.gzip.encode', STREAM_FILTER_WRITE, ['level' => 6]);

        $part1 = substr($input, 0, 20000);
        $part2 = substr($input, 20000);

        fwrite($fp, $part1);
        fflush($fp);

        clearstatcache(true, $path);
        $sizeAfterPart1 = filesize($path);
        $this->assertGreaterThan(10, $sizeAfterPart1, 'Expected gzip bytes before close');

        fwrite($fp, $part2);
        fclose($fp);

        $gz = file_get_contents($path);
        @unlink($path);

        $out = GzipDecoder::decode($gz);
        $this->assertSame($input, $out);
    }

    public function testZlibEncodeFilter()
    {
        $input = str_repeat('zlib filter test ', 4000);

        $path = tempnam(sys_get_temp_dir(), 'fflate-z-');
        $fp = fopen($path, 'wb');
        stream_filter_append($fp, 'fflate.zlib.encode', STREAM_FILTER_WRITE, ['level' => 6]);

        $part1 = substr($input, 0, 16000);
        $part2 = substr($input, 16000);

        fwrite($fp, $part1);
        fflush($fp);

        clearstatcache(true, $path);
        $sizeAfterPart1 = filesize($path);
        $this->assertGreaterThan(2, $sizeAfterPart1, 'Expected zlib bytes before close');

        fwrite($fp, $part2);
        fclose($fp);

        $z = file_get_contents($path);
        @unlink($path);

        $out = ZlibDecoder::decode($z);
        $this->assertSame($input, $out);
    }

    public function testGzipDecodeFilterStreamingRead()
    {
        $input = str_repeat('decode filter streaming ', 5000);
        $gz = GzipEncoder::encode($input, 6);

        $path = tempnam(sys_get_temp_dir(), 'fflate-gz-in-');
        file_put_contents($path, $gz);

        $fp = fopen($path, 'rb');
        stream_filter_append($fp, 'fflate.gzip.decode', STREAM_FILTER_READ);

        $first = fread($fp, 1024);
        $this->assertNotSame('', $first, 'Expected decoded bytes before EOF');

        $rest = stream_get_contents($fp);
        fclose($fp);
        @unlink($path);

        $this->assertSame($input, $first . $rest);
    }

    public function testGzipDecodeFilterConcatenatedMembers()
    {
        $a = str_repeat('memberA-', 3000);
        $b = str_repeat('memberB-', 2000);
        $gz = GzipEncoder::encode($a, 6) . GzipEncoder::encode($b, 6);

        $path = tempnam(sys_get_temp_dir(), 'fflate-gz-cat-');
        file_put_contents($path, $gz);

        $fp = fopen($path, 'rb');
        stream_filter_append($fp, 'fflate.gzip.decode', STREAM_FILTER_READ);
        $out = stream_get_contents($fp);
        fclose($fp);
        @unlink($path);

        $this->assertSame($a . $b, $out);
    }

    public function testZlibDecodeFilterStreamingRead()
    {
        $input = str_repeat('decode zlib streaming ', 6000);
        $z = ZlibEncoder::encode($input, 6);

        $path = tempnam(sys_get_temp_dir(), 'fflate-z-in-');
        file_put_contents($path, $z);

        $fp = fopen($path, 'rb');
        stream_filter_append($fp, 'fflate.zlib.decode', STREAM_FILTER_READ);

        $first = fread($fp, 1024);
        $this->assertNotSame('', $first, 'Expected decoded bytes before EOF');

        $rest = stream_get_contents($fp);
        fclose($fp);
        @unlink($path);

        $this->assertSame($input, $first . $rest);
    }
}
