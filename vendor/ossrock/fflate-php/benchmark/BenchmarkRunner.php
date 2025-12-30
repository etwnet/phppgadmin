<?php

namespace Ossrock\FflatePhp\Benchmark;

use Ossrock\FflatePhp\FflatePhp;

class BenchmarkRunner
{
    private $results = [];
    private $verbose = false;

    public function __construct($verbose = false)
    {
        $this->verbose = $verbose;
    }

    /**
     * Run a benchmark and record timing
     *
     * @param string $name Benchmark name
     * @param callable $callback Function to benchmark
     * @param int $iterations Number of iterations
     * @return array Timing results (min, max, avg, total)
     */
    public function benchmark($name, callable $callback, $iterations = 100)
    {
        gc_collect_cycles();

        $times = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $callback();
            $end = microtime(true);
            $times[] = ($end - $start) * 1000; // Convert to milliseconds
        }

        $stats = [
            'name' => $name,
            'iterations' => $iterations,
            'min' => min($times),
            'max' => max($times),
            'avg' => array_sum($times) / count($times),
            'total' => array_sum($times),
        ];

        $this->results[] = $stats;

        if ($this->verbose) {
            printf(
                "%s: %.2f ms (avg), %.2f ms (min), %.2f ms (max) [%d iterations]\n",
                $name,
                $stats['avg'],
                $stats['min'],
                $stats['max'],
                $iterations
            );
        }

        return $stats;
    }

    /**
     * Print benchmark results table
     */
    public function printResults()
    {
        if (empty($this->results)) {
            echo "No benchmarks to report.\n";
            return;
        }

        echo "\n";
        echo str_repeat("=", 100) . "\n";
        echo "BENCHMARK RESULTS\n";
        echo str_repeat("=", 100) . "\n\n";

        printf("%-50s %12s %12s %12s %12s\n", "Benchmark", "Avg (ms)", "Min (ms)", "Max (ms)", "Total (ms)");
        echo str_repeat("-", 100) . "\n";

        foreach ($this->results as $result) {
            printf(
                "%-50s %12.4f %12.4f %12.4f %12.4f\n",
                $result['name'],
                $result['avg'],
                $result['min'],
                $result['max'],
                $result['total']
            );
        }

        echo str_repeat("=", 100) . "\n\n";
    }

    /**
     * Compare two benchmarks and show speedup/slowdown
     */
    public function compare($name1, $name2)
    {
        $result1 = null;
        $result2 = null;

        foreach ($this->results as $result) {
            if ($result['name'] === $name1) {
                $result1 = $result;
            }
            if ($result['name'] === $name2) {
                $result2 = $result;
            }
        }

        if (!$result1 || !$result2) {
            echo "Could not find both benchmarks to compare.\n";
            return;
        }

        $ratio = $result1['avg'] / $result2['avg'];
        $faster = $ratio > 1 ? $name2 : $name1;
        $speedup = max($ratio, 1 / $ratio);

        printf(
            "%s is %.2fx %s than %s\n",
            $faster,
            $speedup,
            $ratio > 1 ? 'faster' : 'slower',
            $ratio > 1 ? $name1 : $name2
        );
    }

    /**
     * Get all results
     */
    public function getResults()
    {
        return $this->results;
    }
}
