#!/usr/bin/env php
<?php

/**
 * fflate-php Performance Benchmark Suite
 *
 * Run comprehensive performance tests on all compression components
 *
 * Usage:
 *   php benchmark/run.php              # Run all benchmarks
 *   php benchmark/run.php --help       # Show help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ossrock\FflatePhp\FflatePhp;
use Ossrock\FflatePhp\Benchmark\BenchmarkRunner;
use Ossrock\FflatePhp\Benchmark\GzipBenchmark;
use Ossrock\FflatePhp\Benchmark\ZlibBenchmark;
use Ossrock\FflatePhp\Benchmark\StreamBenchmark;
use Ossrock\FflatePhp\Benchmark\StringAPIBenchmark;

// Register filters
FflatePhp::registerFilters();

echo "ℹ️  Running benchmarks (fflate_* + stream classes)\n";
echo "   Native comparisons will be shown if ext-zlib is available.\n\n";

// Show system info
echo "System Info:\n";
echo "- PHP Version: " . phpversion() . "\n";
echo "- Native Zlib: " . (extension_loaded('zlib') ? "Available" : "Not available") . "\n";
echo "- Platform: " . php_uname('s') . " " . php_uname('r') . "\n";
echo "- Memory Limit: " . ini_get('memory_limit') . "\n\n";

// Create benchmark runner
$runner = new BenchmarkRunner($verbose = true);

// Run all benchmarks
$startTime = microtime(true);

$benchmarks = [
    new StringAPIBenchmark(),
    new GzipBenchmark(),
    new ZlibBenchmark(),
    new StreamBenchmark(),
];

foreach ($benchmarks as $benchmark) {
    $benchmark->run($runner);
}

$endTime = microtime(true);
$totalTime = $endTime - $startTime;

// Print summary
$runner->printResults();

printf("Total Benchmark Time: %.2f seconds\n\n", $totalTime);

echo "Legend:\n";
echo "- Avg (ms): Average execution time per iteration\n";
echo "- Min (ms): Minimum execution time\n";
echo "- Max (ms): Maximum execution time\n";
echo "- Total (ms): Sum of all iterations\n\n";

echo "Tips for interpreting results:\n";
echo "1. Average time is most important (less variance is better)\n";
echo "2. Memory usage depends on data size and compression ratio\n";
echo "3. Native zlib is ~20-90x faster but fflate-php is more portable\n";
echo "4. Stream filters are useful for processing large files incrementally\n\n";

if (!extension_loaded('zlib')) {
    echo "ℹ️  Native zlib extension not available - pure PHP comparison not possible\n";
    echo "   Install zlib with: apt-get install php8.x-zlib (Linux)\n";
    echo "                      or enable it in php.ini (Windows)\n\n";
}

echo "✅ Benchmark complete!\n";
