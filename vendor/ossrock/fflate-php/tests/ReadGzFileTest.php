<?php

namespace Ossrock\FflatePhp\Tests;

use Ossrock\FflatePhp\Gzip\GzipEncoder;
use PHPUnit\Framework\TestCase;

class ReadGzFileTest extends TestCase
{
    public function testFflateReadgzfileOutputsDecodedBytesAndReturnsLength()
    {
        $input = str_repeat("hello gzip\n", 5000);
        $gz = GzipEncoder::encode($input, 6);

        $path = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'fflate-php-' . uniqid('', true) . '.gz';
        $this->assertNotFalse(file_put_contents($path, $gz));

        try {
            ob_start();
            $bytes = fflate_readgzfile($path);
            $output = ob_get_clean();

            $this->assertIsInt($bytes);
            $this->assertSame(strlen($input), $bytes);
            $this->assertSame($input, $output);
        } finally {
            @unlink($path);
        }
    }
}
