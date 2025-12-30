<?php

namespace Ossrock\FflatePhp\Benchmark;

class StringAPIBenchmark
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
        echo "\n### STRING API BENCHMARKS (GZIP) ###\n";

        if (!extension_loaded('zlib')) {
            echo "Skip native tests because ext-zlib is not available.\n";
        } else {
            foreach ($this->data as $size => $data) {
                $runner->benchmark(
                    "native gzencode($size)",
                    function () use ($data) {
                        return gzencode($data, 6);
                    },
                    $size === '100KB' ? 20 : 50
                );

                $encoded = gzencode($data, 6);
                $runner->benchmark(
                    "native gzdecode($size)",
                    function () use ($encoded) {
                        return gzdecode($encoded);
                    },
                    $size === '100KB' ? 20 : 50
                );
            }

            echo "\n### STRING API BENCHMARKS (ZLIB) ###\n";
            foreach ($this->data as $size => $data) {
                $runner->benchmark(
                    "native gzcompress($size)",
                    function () use ($data) {
                        return gzcompress($data, 6);
                    },
                    $size === '100KB' ? 20 : 50
                );

                $encoded = gzcompress($data, 6);
                $runner->benchmark(
                    "native gzuncompress($size)",
                    function () use ($encoded) {
                        return gzuncompress($encoded);
                    },
                    $size === '100KB' ? 20 : 50
                );
            }

            echo "\n### STRING API BENCHMARKS (DEFLATE) ###\n";
            foreach ($this->data as $size => $data) {
                $runner->benchmark(
                    "native gzdeflate($size)",
                    function () use ($data) {
                        return gzdeflate($data, 6);
                    },
                    $size === '100KB' ? 20 : 50
                );

                $encoded = gzdeflate($data, 6);
                $runner->benchmark(
                    "native gzinflate($size)",
                    function () use ($encoded) {
                        return gzinflate($encoded);
                    },
                    $size === '100KB' ? 20 : 50
                );
            }
        }

        foreach ($this->data as $size => $data) {
            $runner->benchmark(
                "fflate_gzencode($size)",
                function () use ($data) {
                    return fflate_gzencode($data, 6);
                },
                $size === '100KB' ? 20 : 50
            );

            $encoded = fflate_gzencode($data, 6);
            $runner->benchmark(
                "fflate_gzdecode($size)",
                function () use ($encoded) {
                    return fflate_gzdecode($encoded);
                },
                $size === '100KB' ? 20 : 50
            );
        }

        echo "\n### STRING API BENCHMARKS (ZLIB) ###\n";

        foreach ($this->data as $size => $data) {
            $runner->benchmark(
                "fflate_gzcompress($size)",
                function () use ($data) {
                    return fflate_gzcompress($data, 6);
                },
                $size === '100KB' ? 20 : 50
            );

            $encoded = fflate_gzcompress($data, 6);
            $runner->benchmark(
                "fflate_gzuncompress($size)",
                function () use ($encoded) {
                    return fflate_gzuncompress($encoded);
                },
                $size === '100KB' ? 20 : 50
            );
        }

        echo "\n### STRING API BENCHMARKS (DEFLATE) ###\n";

        foreach ($this->data as $size => $data) {
            $runner->benchmark(
                "fflate_gzdeflate($size)",
                function () use ($data) {
                    return fflate_gzdeflate($data, 6);
                },
                $size === '100KB' ? 20 : 50
            );

            $encoded = fflate_gzdeflate($data, 6);
            $runner->benchmark(
                "fflate_gzinflate($size)",
                function () use ($encoded) {
                    return fflate_gzinflate($encoded);
                },
                $size === '100KB' ? 20 : 50
            );
        }

        // Native comparison
        if (extension_loaded('zlib')) {
            echo "\n### NATIVE STRING API COMPARISON ###\n";

            foreach (array_slice($this->data, 0, 2) as $size => $data) {
                $pureStart = $runner->benchmark(
                    "fflate_gzencode($size)",
                    function () use ($data) {
                        return fflate_gzencode($data, 6);
                    },
                    50
                );

                $nativeStart = $runner->benchmark(
                    "native gzencode($size)",
                    function () use ($data) {
                        return gzencode($data, 6);
                    },
                    50
                );

                $speedup = $pureStart['avg'] / $nativeStart['avg'];
                printf(
                    "Native gzencode is %.2fx %s than pure PHP on %s data\n",
                    $speedup >= 1 ? $speedup : (1 / $speedup),
                    $speedup >= 1 ? 'faster' : 'slower',
                    $size
                );
            }
        }
    }
}
