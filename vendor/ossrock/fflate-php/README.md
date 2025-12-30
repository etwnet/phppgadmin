# fflate-php

Pure PHP implementation of DEFLATE, GZIP, and Zlib compression/decompression with stream filters and `gz*()` function polyfills.

## 🎯 Overview

**fflate-php** is a port of the JavaScript [fflate](https://github.com/101arrowz/fflate) library to PHP, providing:

- ✅ Pure PHP DEFLATE compression/decompression
- ✅ GZIP format support
- ✅ Zlib format support
- ✅ PHP stream filters for on-the-fly compression
- ✅ Drop-in polyfills for `gz*()` functions
- ✅ Automatic fallback when native zlib is unavailable
- ✅ PHP 7.2+ compatible
- ✅ 64-bit architecture required

**Use cases:**

- phpPgAdmin backup/restore on systems without zlib
- Streaming compression for large files
- Cross-platform PHP applications
- Testing both native and pure PHP implementations

## ⚠️ Important Notes

### Performance

Pure PHP implementation is **20-90× slower** than native zlib. Use native zlib when available for production workloads.

### GZIP ISIZE Limitation

GZIP's ISIZE field is 32-bit. Files >4GB are supported, but size is stored modulo 2³² (matching gzip/zlib behavior).

### Requirements

- PHP 7.2 or higher
- 64-bit architecture (required for proper bitwise operations)

## 📦 Installation

```bash
composer require ossrock/fflate-php
```

## 🚀 Quick Start

### Basic GZIP Decompression

```php
<?php
require 'vendor/autoload.php';

use Ossrock\FflatePhp\Gzip\GzipDecoder;

$compressed = file_get_contents('data.sql.gz');
$decompressed = GzipDecoder::decode($compressed);

if ($decompressed === false) {
    die('Decompression failed');
}

file_put_contents('data.sql', $decompressed);
```

### Using Polyfill Functions

```php
<?php
require 'vendor/autoload.php';

// If native gzdecode() doesn't exist, our polyfill will be used
$decompressed = gzdecode($compressed);
```

### Basic Compression

```php
<?php
require 'vendor/autoload.php';

use Ossrock\FflatePhp\Gzip\GzipEncoder;
use Ossrock\FflatePhp\Zlib\ZlibEncoder;

$gz = GzipEncoder::encode('hello', 6);
$z  = ZlibEncoder::encode('hello', 6);
```

### Choosing Pure PHP vs Native

PHP cannot override built-in `gz*()` functions when `ext-zlib` is installed. This library therefore provides:

- `fflate_*` functions (always available): guaranteed pure-PHP implementation
- `gz*` / `readgzfile` polyfills (only when missing): drop-in fallback when `ext-zlib` is not installed

```php
<?php
require 'vendor/autoload.php';

// Explicit pure-PHP call (always uses this library)
$compressed = fflate_gzencode('hello', 6);
$plain      = fflate_gzdecode($compressed);

// Native or pure-PHP call (drop-in replacement)
$compressed2 = gzencode('hello', 6);
$plain2      = gzdecode($compressed2);
```

### Stream Filters (True Streaming)

```php
<?php
// Register stream filters
FflatePhp::registerFilters();

// GZIP compress while writing to browser
$fp = fopen('php://filter/write=gzip.encode/resource=php://output', 'wb');
// For pure PHP implementation...
//$fp = fopen('php://filter/write=fflate.gzip.encode/resource=php://output', 'wb');
fwrite($fp, $dataChunk1);
fwrite($fp, $dataChunk2);
fclose($fp); // writes gzip footer (CRC/ISIZE)

// GZIP decompress while reading (streams output as it becomes available)
$fp = fopen('php://filter/read=gzip.decode/resource=dump.sql.gz', 'rb');
// For pure PHP implementation...
//$fp = fopen('php://filter/read=fflate.gzip.decode/resource=dump.sql.gz', 'rb');
//
while ($chunk = fread($fp, 8192)) {
    echo $chunk;
}
fclose($fp);

// Zlib variants:
// - write=zlib.encode
// - read=zlib.decode
// Pure PHP:
// - write=fflate.zlib.encode
// - read=fflate.zlib.decode
```

## 📚 API Reference

### FflatePhp (Main Facade)

```php
// Check availability
FflatePhp::isNativeAvailable(): bool

// Configuration
FflatePhp::setDefaultLevel(int $level): void  // 0-9, default: 6
FflatePhp::getDefaultLevel(): int

// Stream filters
FflatePhp::registerFilters(): void

// Version
FflatePhp::getVersion(): string
```

### GzipDecoder

```php
// Decode GZIP data
GzipDecoder::decode(string $data): string|false
```

### GzipEncoder

```php
// Encode GZIP data
GzipEncoder::encode(string $data, int $level = 6): string
```

### ZlibEncoder / ZlibDecoder

```php
ZlibEncoder::encode(string $data, int $level = 6): string
ZlibDecoder::decode(string $data): string|false
```

## 🏗️ Project Status

**Version: 0.1.0-dev (Feature-Complete)**

### ✅ Implemented (95%)

- [x] DEFLATE inflate (decompression) - fully RFC 1951 compliant
- [x] DEFLATE deflate (compression) - stored + fixed Huffman encoding
- [x] Huffman tree encoding/decoding
- [x] LZ77 back-references with 32KB sliding window
- [x] GZIP decoder with concatenated member support
- [x] GZIP encoder with CRC32 + ISIZE
- [x] Zlib encoder/decoder with Adler32 checksums
- [x] CRC32 and Adler32 checksums (table-based + native)
- [x] Bit-level I/O operations (reader/writer)
- [x] Stream classes for incremental encode/decode
- [x] php://filter stream filters (4 filters: gzip/zlib encode/decode)
- [x] Core `gz*()` polyfill functions (string APIs)
- [x] File-handle `gz*()` polyfills (`gzopen`, `gzread`, `gzwrite`, `gzclose`, etc.)
- [x] Best-effort `gzseek`/`gztell`/`gzrewind` for seekable streams
- [x] Mode detection and switching
- [x] 18 comprehensive PHPUnit tests (all passing)
- [x] PHP 8.2+ deprecation fixes
- [x] Full README with examples and API documentation

### 📋 Future Enhancements (Post-v1.0.0)

- [ ] Dynamic Huffman encoding (better compression ratio, higher complexity)
- [ ] Carry LZ77 dictionary across streaming blocks (improved streaming ratio)
- [ ] ZIP archive support (v2.0+)
- [ ] Performance benchmarks and optimization
- [ ] Integration guides (phpPgAdmin, WordPress, etc.)

## 🧪 Testing

```bash
# Install dependencies
composer install

# Run all tests
vendor/bin/phpunit
```

## 🧩 Notes & Limitations

### Performance

Pure PHP implementation is **20-90× slower** than native zlib. For production workloads, native zlib is strongly recommended. Use fflate-php as a fallback when native zlib is unavailable.

### Stream Encoding

Stream _encode_ filters output bytes as you write, but currently emit independent DEFLATE blocks per chunk (dictionary is not carried across blocks yet), so compression ratio may be slightly worse than native zlib for very long streams.

### Stream Decoding

Stream _decode_ filters are fully incremental and support concatenated gzip members (multiple gzip streams appended together automatically).

### Seeking Limitations

- `gzseek()` works on read streams with best-effort semantics:
  - **Forward seeks**: accomplished by reading and discarding decompressed bytes (O(n))
  - **Backward seeks**: require underlying stream to be seekable; stream is rewound and filter is reset
  - **SEEK_END**: not supported (would require full decompression to determine uncompressed size)
- `gzseek()` on write streams is not supported with a warning
- `gzopen()` doesn't support `+` modes (simultaneous read/write)

## 🤝 Contributing

Contributions are welcome! This is a complex project and there's much work to be done:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## 📄 License

MIT License - see [LICENSE](LICENSE) file for details.

This project is a port of [fflate](https://github.com/101arrowz/fflate) by Arjun Barrett, which is also MIT licensed.

## 🙏 Credits

- **fflate**: Original JavaScript implementation by [Arjun Barrett](https://github.com/101arrowz)
- **DEFLATE Algorithm**: RFC 1951
- **GZIP Format**: RFC 1952
- **Zlib Format**: RFC 1950

## 📞 Support

- GitHub Issues: [Report bugs or request features](https://github.com/ossrock/fflate-php/issues)

---

**Note**: This library is feature-complete for v1.0.0. API is stable. Production use is recommended with proper error handling.
