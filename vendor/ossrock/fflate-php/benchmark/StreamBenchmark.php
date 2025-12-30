<?php

namespace Ossrock\FflatePhp\Benchmark;

class StreamBenchmark
{
    private $data = [];

    public function __construct()
    {
        $this->data['1KB'] = str_repeat('The quick brown fox jumps over the lazy dog. ', 22);
        $this->data['100KB'] = str_repeat($this->data['1KB'], 100);
    }

    public function run(BenchmarkRunner $runner)
    {
        echo "\n### STREAM FILTER BENCHMARKS ###\n";

        foreach ($this->data as $size => $data) {
            // GZIP encoding stream
            $runner->benchmark(
                "Stream fflate.gzip.encode ($size)",
                function () use ($data) {
                    $this->streamEncode($data, 'fflate.gzip.encode');
                },
                $size === '100KB' ? 20 : 50
            );

            // ZLIB encoding stream
            $runner->benchmark(
                "Stream fflate.zlib.encode ($size)",
                function () use ($data) {
                    $this->streamEncode($data, 'fflate.zlib.encode');
                },
                $size === '100KB' ? 20 : 50
            );
        }

        // Native stream comparison
        if (extension_loaded('zlib')) {
            echo "\n### NATIVE STREAM COMPARISON ###\n";
            foreach (array_slice($this->data, 0, 1) as $size => $data) {
                $runner->benchmark(
                    "Native zlib.deflate stream ($size)",
                    function () use ($data) {
                        return $this->streamEncode($data, 'zlib.deflate');
                    },
                    50
                );

                $runner->compare("Stream fflate.gzip.encode ($size)", "Native zlib.deflate stream ($size)");
            }
        }
    }

    private function streamEncode($data, $filter)
    {
        $stream = fopen('php://memory', 'r+');
        stream_filter_append($stream, $filter, STREAM_FILTER_WRITE);
        fwrite($stream, $data);
        rewind($stream);
        $result = stream_get_contents($stream);
        fclose($stream);
        return $result;
    }
}
