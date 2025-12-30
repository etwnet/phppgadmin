<?php

namespace Ossrock\FflatePhp\Benchmark;

use Ossrock\FflatePhp\Zlib\ZlibEncoder;
use Ossrock\FflatePhp\Stream\ZlibStreamDecoder;
use Ossrock\FflatePhp\Stream\ZlibStreamEncoder;

class ZlibBenchmark
{
    private $data = [];

    public function __construct()
    {
        $this->data['1KB'] = str_repeat('The quick brown fox jumps over the lazy dog. ', 22);
        $this->data['10KB'] = str_repeat($this->data['1KB'], 10);
        $this->data['100KB'] = str_repeat($this->data['1KB'], 100);
    }

    public function run(BenchmarkRunner $runner)
    {
        echo "\n### ZLIB BENCHMARKS ###\n";

        foreach ($this->data as $size => $data) {
            $runner->benchmark(
                "ZlibStreamEncoder compress ($size)",
                function () use ($data) {
                    $encoder = new ZlibStreamEncoder(6);
                    $out = $encoder->append($data);
                    return $out . $encoder->finish();
                },
                $size === '100KB' ? 20 : 50
            );

            $encoder = new ZlibStreamEncoder(6);
            $out = $encoder->append($data);
            $compressed = $out . $encoder->finish();

            $runner->benchmark(
                "ZlibStreamDecoder decompress ($size)",
                function () use ($compressed) {
                    $decoder = new ZlibStreamDecoder();
                    return $decoder->append($compressed, true); // closing=true
                },
                $size === '100KB' ? 20 : 50
            );

            $ratio = strlen($compressed) / strlen($data);
            printf(
                "Compression ratio ($size): %.2f%% (original: %d bytes, compressed: %d bytes)\n",
                $ratio * 100,
                strlen($data),
                strlen($compressed)
            );
        }

        if (extension_loaded('zlib')) {
            echo "\n### NATIVE ZLIB COMPARISON ###\n";
            foreach (array_slice($this->data, 0, 2) as $size => $data) {
                $runner->benchmark(
                    "Native gzcompress ($size)",
                    function () use ($data) {
                        return gzcompress($data, 6);
                    },
                    50
                );

                $compressed = gzcompress($data, 6);
                $runner->benchmark(
                    "Native gzuncompress ($size)",
                    function () use ($compressed) {
                        return gzuncompress($compressed);
                    },
                    50
                );

                $runner->compare("ZlibStreamEncoder compress ($size)", "Native gzcompress ($size)");
                $runner->compare("ZlibStreamDecoder decompress ($size)", "Native gzuncompress ($size)");
            }
        }
    }
}
