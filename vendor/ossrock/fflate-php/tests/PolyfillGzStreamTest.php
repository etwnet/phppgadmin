<?php

namespace Ossrock\FflatePhp\Tests;

use Ossrock\FflatePhp\FflatePhp;
use PHPUnit\Framework\TestCase;

class PolyfillGzStreamTest extends TestCase
{
    public function testFflateGzopenReadWriteRoundtrip()
    {
        FflatePhp::registerFilters();

        $path = tempnam(sys_get_temp_dir(), 'fflate-gzopen-');
        $this->assertNotFalse($path);

        $w = fflate_gzopen($path, 'wb6');
        $this->assertNotFalse($w);
        $this->assertSame(strlen('hello'), fflate_gzwrite($w, 'hello'));
        $this->assertSame(strlen(' world'), fflate_gzwrite($w, ' world'));
        $this->assertTrue(fflate_gzclose($w));

        $r = fflate_gzopen($path, 'rb');
        $this->assertNotFalse($r);
        $data = '';
        while (!fflate_gzeof($r)) {
            $chunk = fflate_gzread($r, 3);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fflate_gzclose($r);

        @unlink($path);

        $this->assertSame('hello world', $data);
    }

    public function testFflateGzseekAndGztellBestEffort()
    {
        FflatePhp::registerFilters();

        $path = tempnam(sys_get_temp_dir(), 'fflate-gzseek-');
        $this->assertNotFalse($path);

        $payload = str_repeat('0123456789', 100); // 1000 bytes

        $w = fflate_gzopen($path, 'wb6');
        $this->assertNotFalse($w);
        $this->assertSame(strlen($payload), fflate_gzwrite($w, $payload));
        $this->assertTrue(fflate_gzclose($w));

        $r = fflate_gzopen($path, 'rb');
        $this->assertNotFalse($r);

        $this->assertSame(0, fflate_gztell($r));
        $this->assertSame('01234', fflate_gzread($r, 5));
        $this->assertSame(5, fflate_gztell($r));

        // Forward seek (discard)
        $this->assertSame(0, fflate_gzseek($r, 100, SEEK_SET));
        $this->assertSame(100, fflate_gztell($r));
        $this->assertSame(substr($payload, 100, 10), fflate_gzread($r, 10));

        // Backward seek (rewind+redecode+discard)
        $this->assertSame(0, fflate_gzseek($r, 0, SEEK_SET));
        $this->assertSame(0, fflate_gztell($r));
        $this->assertSame(substr($payload, 0, 20), fflate_gzread($r, 20));

        fflate_gzclose($r);
        @unlink($path);
    }
}
