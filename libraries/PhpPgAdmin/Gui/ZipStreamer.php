<?php
namespace PhpPgAdmin\Gui;

/**
 * Minimal in-process ZIP streamer using raw DEFLATE via deflate_init/deflate_add.
 * Exposes a stream wrapper protocol 'phppgadminzip://' where the path is an id.
 * Formatter code writes to fopen('phppgadminzip://<id>', 'w') and data is streamed
 * directly to php://output as a ZIP file without buffering the whole dump.
 */
class ZipStreamer
{
    protected $out; // output resource (php://output)
    protected $bytesWritten = 0;
    protected $entries = [];

    // current file state
    protected $current = null;

    public function __construct($out)
    {
        $this->out = $out;
        $this->bytesWritten = 0;
    }

    protected function writeRaw(string $data): void
    {
        $written = fwrite($this->out, $data);
        if ($written === false) {
            throw new \RuntimeException('Failed to write to output stream');
        }
        $this->bytesWritten += $written;
        // Ensure data is flushed to the webserver / client promptly
        // fflush on the php://output handle plus PHP-level flushes
        @fflush($this->out);
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        if (function_exists('flush')) {
            @flush();
        }
    }

    protected static function dosTimeDate(int $timestamp = null): array
    {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $d = getdate($timestamp);
        $dosTime = ($d['seconds'] >> 1) | ($d['minutes'] << 5) | ($d['hours'] << 11);
        $dosDate = ($d['mday']) | ($d['mon'] << 5) | (($d['year'] - 1980) << 9);
        return [$dosTime & 0xffff, $dosDate & 0xffff];
    }

    protected static function packLE64(int $n): string
    {
        $low = $n & 0xffffffff;
        $high = ($n >> 32) & 0xffffffff;
        return pack('V', $low) . pack('V', $high);
    }

    public function startFile(string $name): void
    {
        if ($this->current !== null) {
            throw new \LogicException('A file is already open');
        }

        // Record offset for central directory
        $localHeaderOffset = $this->bytesWritten;

        // Use data descriptor (set bit 3) and zero sizes in local header
        $gpFlag = 0x08;
        $compMethod = 8; // deflate
        list($modTime, $modDate) = self::dosTimeDate();

        $fname = $name;
        $fnameLen = strlen($fname);

        $local = '';
        $local .= pack('V', 0x04034b50);
        $local .= pack('v', 20); // version needed
        $local .= pack('v', $gpFlag);
        $local .= pack('v', $compMethod);
        $local .= pack('v', $modTime);
        $local .= pack('v', $modDate);
        $local .= pack('V', 0); // crc32 unknown
        $local .= pack('V', 0); // comp size unknown
        $local .= pack('V', 0); // uncomp size unknown
        $local .= pack('v', $fnameLen);
        $local .= pack('v', 0); // extra len
        $local .= $fname;

        $this->writeRaw($local);

        // initialize current file meta
        $this->current = (object) [
            'name' => $fname,
            'localHeaderOffset' => $localHeaderOffset,
            'crcCtx' => hash_init('crc32b'),
            'uncompressedSize' => 0,
            'compressedSize' => 0,
            'deflateCtx' => deflate_init(
                defined('ZLIB_ENCODING_RAW') ? ZLIB_ENCODING_RAW : -15,
                ['level' => -1]
            ),
        ];
    }

    public function writeData(string $data): void
    {
        if ($this->current === null) {
            throw new \LogicException('No open file to write to');
        }

        hash_update($this->current->crcCtx, $data);
        $this->current->uncompressedSize += strlen($data);

        $deflated = deflate_add($this->current->deflateCtx, $data, ZLIB_NO_FLUSH);
        if ($deflated !== '') {
            $this->writeRaw($deflated);
            $this->current->compressedSize += strlen($deflated);
        }
    }

    public function finishFile(): void
    {
        if ($this->current === null) {
            return;
        }

        // Finish deflate stream
        $tail = deflate_add($this->current->deflateCtx, '', ZLIB_FINISH);
        if ($tail !== '') {
            $this->writeRaw($tail);
            $this->current->compressedSize += strlen($tail);
        }

        // Compute CRC32 (unsigned)
        $crcHex = hash_final($this->current->crcCtx);
        $crc32 = (int) sprintf('%u', hexdec($crcHex));

        // Write data descriptor (with signature). Use ZIP64 sizes when necessary.
        $desc = '';
        $desc .= pack('V', 0x08074b50);
        $desc .= pack('V', $crc32);
        if ($this->current->compressedSize > 0xFFFFFFFF || $this->current->uncompressedSize > 0xFFFFFFFF) {
            $desc .= self::packLE64($this->current->compressedSize);
            $desc .= self::packLE64($this->current->uncompressedSize);
        } else {
            $desc .= pack('V', $this->current->compressedSize);
            $desc .= pack('V', $this->current->uncompressedSize);
        }
        $this->writeRaw($desc);

        // Record entry for central directory
        $this->entries[] = [
            'name' => $this->current->name,
            'localHeaderOffset' => $this->current->localHeaderOffset,
            'crc32' => $crc32,
            'compressedSize' => $this->current->compressedSize,
            'uncompressedSize' => $this->current->uncompressedSize,
        ];

        // Clear current file
        $this->current = null;
    }

    /**
     * Write central directory and end of central directory records.
     * Must be called after all files are finished.
     */
    public function finish(): void
    {
        // Determine if ZIP64 is required
        // Record central directory start and entry count
        $centralStart = $this->bytesWritten;
        $entries = count($this->entries);

        $useZip64 = false;
        foreach ($this->entries as $ee) {
            if ($ee['compressedSize'] > 0xFFFFFFFF || $ee['uncompressedSize'] > 0xFFFFFFFF || $ee['localHeaderOffset'] > 0xFFFFFFFF) {
                $useZip64 = true;
                break;
            }
        }

        // Write central directory entries
        foreach ($this->entries as $e) {
            $fname = $e['name'];
            $fnameLen = strlen($fname);
            list($modTime, $modDate) = self::dosTimeDate();

            $cent = '';
            $cent .= pack('V', 0x02014b50);
            $cent .= pack('v', $useZip64 ? 45 : 20); // version made by
            $cent .= pack('v', $useZip64 ? 45 : 20); // version needed
            $cent .= pack('v', 0x08); // gp flag
            $cent .= pack('v', 8); // compression method
            $cent .= pack('v', $modTime);
            $cent .= pack('v', $modDate);
            $cent .= pack('V', $e['crc32']);

            // compression/uncompressed sizes (placeholders if ZIP64 needed)
            if ($e['compressedSize'] > 0xFFFFFFFF || $e['uncompressedSize'] > 0xFFFFFFFF) {
                $cent .= pack('V', 0xFFFFFFFF);
                $cent .= pack('V', 0xFFFFFFFF);
            } else {
                $cent .= pack('V', $e['compressedSize']);
                $cent .= pack('V', $e['uncompressedSize']);
            }

            $cent .= pack('v', $fnameLen);

            // Prepare extra field if zip64 needed for this entry
            $extra = '';
            $zip64data = '';
            if ($e['uncompressedSize'] > 0xFFFFFFFF) {
                $zip64data .= self::packLE64($e['uncompressedSize']);
            }
            if ($e['compressedSize'] > 0xFFFFFFFF) {
                $zip64data .= self::packLE64($e['compressedSize']);
            }
            if ($e['localHeaderOffset'] > 0xFFFFFFFF) {
                $zip64data .= self::packLE64($e['localHeaderOffset']);
            }
            if ($zip64data !== '') {
                $extra .= pack('v', 0x0001); // ZIP64 extra ID
                $extra .= pack('v', strlen($zip64data));
                $extra .= $zip64data;
            }

            $cent .= pack('v', strlen($extra)); // extra len
            $cent .= pack('v', 0); // comment len
            $cent .= pack('v', 0); // disk number start
            $cent .= pack('v', 0); // internal attrs
            $cent .= pack('V', 0); // external attrs

            // local header offset (placeholder if ZIP64)
            if ($e['localHeaderOffset'] > 0xFFFFFFFF) {
                $cent .= pack('V', 0xFFFFFFFF);
            } else {
                $cent .= pack('V', $e['localHeaderOffset']);
            }

            $cent .= $fname;
            $cent .= $extra;

            $this->writeRaw($cent);
        }

        $centralEnd = $this->bytesWritten;
        $centralSize = $centralEnd - $centralStart;
        $centralOffset = $centralStart;

        // End of central directory
        if ($useZip64 || $centralSize > 0xFFFFFFFF || $centralOffset > 0xFFFFFFFF || $entries > 0xFFFF) {
            // EOCD64 record
            $eocd64 = '';
            $eocd64 .= pack('V', 0x06064b50);
            $eocd64 .= self::packLE64(44); // size of remaining EOCD64 record
            $eocd64 .= pack('v', 45); // version made by
            $eocd64 .= pack('v', 45); // version needed
            $eocd64 .= pack('V', 0); // disk number
            $eocd64 .= pack('V', 0); // disk where central starts
            $eocd64 .= self::packLE64($entries); // number of entries on this disk
            $eocd64 .= self::packLE64($entries); // total number of entries
            $eocd64 .= self::packLE64($centralSize);
            $eocd64 .= self::packLE64($centralOffset);
            $this->writeRaw($eocd64);

            // EOCD64 locator
            $locator = '';
            $locator .= pack('V', 0x07064b50);
            $locator .= pack('V', 0); // number of disk with EOCD64
            $locator .= self::packLE64($centralEnd); // offset of EOCD64
            $locator .= pack('V', 1); // total number of disks
            $this->writeRaw($locator);

            // Write classic EOCD with 0xFFFF/0xFFFFFFFF placeholders
            $eocd = '';
            $eocd .= pack('V', 0x06054b50);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('V', 0xFFFFFFFF);
            $eocd .= pack('V', 0xFFFFFFFF);
            $eocd .= pack('v', 0); // comment len

            $this->writeRaw($eocd);
        } else {
            $eocd = '';
            $eocd .= pack('V', 0x06054b50);
            $eocd .= pack('v', 0); // disk number
            $eocd .= pack('v', 0); // disk where central starts
            $eocd .= pack('v', $entries);
            $eocd .= pack('v', $entries);
            $eocd .= pack('V', $centralSize);
            $eocd .= pack('V', $centralOffset);
            $eocd .= pack('v', 0); // comment len

            $this->writeRaw($eocd);
        }

        // Ensure final data flushed to client
        if (function_exists('fflush')) {
            @fflush($this->out);
        }
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        if (function_exists('flush')) {
            @flush();
        }
    }
}

/**
 * Stream wrapper that routes writes to a ZipStreamer instance by id.
 * Path: phppgadminzip://<id>
 */
class ZipWriterStream
{
    public $context;
    protected $id;
    protected $stream;

    protected static $instances = [];
    protected static $registered = false;

    public static function registerWrapper(): void
    {
        if (!self::$registered) {
            $wrappers = stream_get_wrappers();
            if (!in_array('phppgadminzip', $wrappers, true)) {
                @stream_wrapper_register('phppgadminzip', __CLASS__);
            }
            self::$registered = true;
        }
    }

    public static function addInstance(string $id, ZipStreamer $inst): void
    {
        self::$instances[$id] = $inst;
    }

    public static function removeInstance(string $id): void
    {
        unset(self::$instances[$id]);
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        // path like phppgadminzip://<id>
        $parts = parse_url($path);
        $this->id = $parts['host'] ?? ($parts['path'] ?? '');
        if (!isset(self::$instances[$this->id])) {
            return false;
        }
        $this->stream = self::$instances[$this->id];
        return true;
    }

    public function stream_write($data)
    {
        $this->stream->writeData($data);
        return strlen($data);
    }

    public function stream_close()
    {
        // nothing here; ZipStreamer.finish() will be called explicitly
    }

    public function stream_eof()
    {
        return true;
    }
}
