# Performance Benchmarks for fflate-php

This directory contains comprehensive performance benchmarks for the fflate-php compression library.

## Running Benchmarks

### Basic Run (Pure PHP Implementation)

```bash
php benchmark/run.php
```

Uses the pure PHP implementation for fair testing, even if native zlib is available.

### Comparing with Native Zlib

```bash
php benchmark/run.php --native
```

Uses native zlib extension if available, for comparison.

## What Gets Tested

### 1. String API Benchmarks

Tests the direct string compression/decompression functions:

- **GZIP**: `gzencode()`, `gzdecode()`
- **Zlib**: `gzcompress()`, `gzuncompress()`
- **Deflate**: `gzdeflate()`, `gzinflate()`

Data sizes: 1KB, 10KB, 100KB

### 2. Stream Class Benchmarks

Tests the incremental stream encoder/decoder classes:

- **GzipStreamEncoder/Decoder**: Streaming GZIP compression
- **ZlibStreamEncoder/Decoder**: Streaming Zlib compression

Data sizes: 1KB, 10KB, 100KB, 1MB

Includes compression ratios for each data size.

### 3. Stream Filter Benchmarks

Tests the PHP stream filter integration:

- **fflate.gzip.encode**: GZIP encoding filter
- **fflate.zlib.encode**: Zlib encoding filter

Useful for processing large files with minimal memory usage.

### 4. Native Comparison

When native zlib is available, benchmarks show performance ratio comparisons:

- Pure PHP vs native for string APIs
- Stream filters vs native stream filters

## Understanding Results

### Key Metrics

- **Avg (ms)**: Average execution time (most important for performance)
- **Min (ms)**: Minimum execution time
- **Max (ms)**: Maximum execution time (shows variance/GC pauses)
- **Total (ms)**: Sum of all iterations

### Performance Expectations

**Pure PHP Implementation:**

- String APIs (1KB): ~0.01 ms (very fast for small data)
- String APIs (100KB): ~0.3 ms
- Stream classes (1KB): ~0.4 ms
- Stream filters (1KB): ~0.3 ms
- Compression ratio: 1-7% depending on data

**Native Zlib:**

- Generally 20-90x faster than pure PHP
- Used for best performance in production

### Important Notes

1. **Variability**: The `Max` times show garbage collection or system pauses
2. **Compression Ratios**: Repetitive test data compresses extremely well (1-7%)
3. **Stream vs. String APIs**: Stream classes are more flexible but slightly slower
4. **Memory**: Pure PHP is usable for reasonable file sizes; native zlib for production

## Use Cases

### When to Use Pure PHP

- Portable environment without native zlib
- Testing fallback behavior
- Learning compression algorithms
- Processing small data (< 100KB)

### When to Use Native Zlib

- Production environments
- Processing large files
- Performance-critical applications

## Development

All benchmark classes are in the `Ossrock\FflatePhp\Benchmark` namespace:

- `BenchmarkRunner.php`: Core timing and comparison logic
- `GzipBenchmark.php`: GZIP encoder/decoder tests
- `ZlibBenchmark.php`: Zlib encoder/decoder tests
- `StreamBenchmark.php`: Stream filter tests
- `StringAPIBenchmark.php`: String function tests
- `run.php`: Main entry point

To add new benchmarks:

1. Create a new class in `Benchmark` namespace
2. Implement `run(BenchmarkRunner $runner)` method
3. Call `$runner->benchmark()` for each test
4. Add the benchmark to `benchmark/run.php`
