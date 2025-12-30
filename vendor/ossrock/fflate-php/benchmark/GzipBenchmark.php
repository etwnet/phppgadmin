<?php

namespace Ossrock\FflatePhp\Benchmark;

use Ossrock\FflatePhp\Stream\GzipStreamEncoder;
use Ossrock\FflatePhp\Stream\GzipStreamDecoder;

class GzipBenchmark
{
    private $data = [];

    public function __construct()
    {
        // Generate test data of various sizes
        $this->data['1KB'] = str_repeat('The quick brown fox jumps over the lazy dog. ', 22); // ~1KB
        $this->data['10KB'] = str_repeat($this->data['1KB'], 10); // ~10KB
        $this->data['100KB'] = str_repeat($this->data['1KB'], 100); // ~100KB
        $this->data['1MB'] = str_repeat($this->data['1KB'], 1000); // ~1MB
    }

    public function run(BenchmarkRunner $runner)
    {
        echo "\n### GZIP BENCHMARKS ###\n";

        foreach ($this->data as $size => $data) {
            // Pure PHP compression using stream encoder
            $runner->benchmark(
                "GzipStreamEncoder compress ($size)",
                function () use ($data) {
                    $encoder = new GzipStreamEncoder(6);
                    $out = $encoder->append($data);
                    return $out . $encoder->finish();
                },
                $size === '1MB' ? 10 : ($size === '100KB' ? 20 : 50)
            );

            // Test decompression
            $encoder = new GzipStreamEncoder(6);
            $out = $encoder->append($data);
            $compressed = $out . $encoder->finish();

            $runner->benchmark(
                "GzipStreamDecoder decompress ($size)",
                function () use ($compressed) {
                    $decoder = new GzipStreamDecoder();
                    return $decoder->append($compressed, true); // closing=true
                },
                $size === '1MB' ? 10 : ($size === '100KB' ? 20 : 50)
            );

            // Compare compression ratios
            $ratio = strlen($compressed) / strlen($data);
            printf(
                "Compression ratio ($size): %.2f%% (original: %d bytes, compressed: %d bytes)\n",
                $ratio * 100,
                strlen($data),
                strlen($compressed)
            );
        }

        // Native comparison
        if (extension_loaded('zlib')) {
            echo "\n### NATIVE ZLIB COMPARISON ###\n";
            foreach (array_slice($this->data, 0, 2) as $size => $data) {
                $runner->benchmark(
                    "Native gzencode ($size)",
                    function () use ($data) {
                        return gzencode($data, 6);
                    },
                    50
                );

                $compressed = gzencode($data, 6);
                $runner->benchmark(
                    "Native gzdecode ($size)",
                    function () use ($compressed) {
                        return gzdecode($compressed);
                    },
                    50
                );

                $runner->compare("GzipStreamEncoder compress ($size)", "Native gzencode ($size)");
                $runner->compare("GzipStreamDecoder decompress ($size)", "Native gzdecode ($size)");
            }
        }
    }
}
