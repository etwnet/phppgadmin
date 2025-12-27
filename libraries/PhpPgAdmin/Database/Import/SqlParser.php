<?php

namespace PhpPgAdmin\Database\Import;

use PhpPgAdmin\Database\Import\CompressionReader;
use PhpPgAdmin\Database\Import\LocalFileReader;
use PhpPgAdmin\Database\Import\GzipReader;
use PhpPgAdmin\Database\Import\Bzip2Reader;
use PhpPgAdmin\Database\Import\ZipEntryReader;

class SqlParser
{
    /**
     * Parse a chunk from file starting at $offset. Returns:
     * [
     *  'statements' => array of complete SQL statements (including terminators),
     *  'consumed' => number of bytes read from file (int),
     *  'eof' => bool,
     *  'remainder' => leftover partial SQL string to carry across requests
     * ]
     */
    public static function parseChunk(string $filePath, int $offset, int $maxBytes, string $existingBuffer = ''): array
    {
        // Prefer Reader-based parsing which supports zip/gzip/bzip2 streams.
        try {
            $type = CompressionReader::detect($filePath);
            switch ($type) {
                case 'zip':
                    $zip = new \ZipArchive();
                    $entry = null;
                    if ($zip->open($filePath) === true) {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $name = $zip->getNameIndex($i);
                            if (substr($name, -1) !== '/') { // skip directories
                                $entry = $name;
                                break;
                            }
                        }
                        $zip->close();
                    }
                    if ($entry === null) {
                        throw new \Exception('No suitable entry in zip');
                    }
                    $reader = new ZipEntryReader($filePath, $entry);
                    break;
                case 'gzip':
                    $reader = new GzipReader($filePath);
                    break;
                case 'bzip2':
                    $reader = new Bzip2Reader($filePath);
                    break;
                default:
                    $reader = new LocalFileReader($filePath);
                    break;
            }

            if ($offset > 0) {
                $reader->seek($offset);
            }

            $res = self::parseFromReader($reader, $maxBytes, $existingBuffer);
            $reader->close();
            return $res;
        } catch (\Exception $e) {
            // Fall back to original file-based logic on any reader error
        }

        // Fallback: read directly from file path (legacy behaviour)
        $type = CompressionReader::detect($filePath);
        $data = '';
        $eof = false;
        if ($type === 'gzip' && function_exists('gzopen')) {
            $h = @gzopen($filePath, 'rb');
            if ($h === false) {
                return ['statements' => [], 'consumed' => 0, 'eof' => true, 'remainder' => $existingBuffer];
            }
            @gzseek($h, $offset);
            $data = gzread($h, $maxBytes);
            $eof = gzeof($h);
            gzclose($h);
        } else {
            $h = @fopen($filePath, 'rb');
            if ($h === false) {
                return ['statements' => [], 'consumed' => 0, 'eof' => true, 'remainder' => $existingBuffer];
            }
            fseek($h, $offset);
            $data = fread($h, $maxBytes);
            $eof = feof($h);
            fclose($h);
        }

        $buf = $existingBuffer . $data;
        $len = strlen($buf);
        if ($len === 0) {
            return ['statements' => [], 'consumed' => 0, 'eof' => $eof, 'remainder' => $existingBuffer];
        }

        $statements = [];
        $start = 0;
        $inSingle = false;
        $inDouble = false;
        $inBlock = false;
        $inDollar = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $buf[$i];

            // inside block comment: look for */
            if ($inBlock) {
                if ($c === '*' && ($i + 1) < $len && $buf[$i + 1] === '/') {
                    $inBlock = false;
                    $i++;
                }
                continue;
            }

            // inside single-quoted string: handle doubled single-quotes as escape
            if ($inSingle) {
                if ($c === "'") {
                    if (($i + 1) < $len && $buf[$i + 1] === "'") {
                        // escaped '' -> skip the second quote
                        $i++;
                        continue;
                    }
                    $inSingle = false;
                }
                continue;
            }

            // inside double-quoted identifier: handle doubled double-quotes
            if ($inDouble) {
                if ($c === '"') {
                    if (($i + 1) < $len && $buf[$i + 1] === '"') {
                        $i++;
                        continue;
                    }
                    $inDouble = false;
                }
                continue;
            }

            // inside dollar-quoted string
            if ($inDollar !== null) {
                $tag = $inDollar;
                $tlen = strlen($tag);
                if ($tlen > 0 && substr($buf, $i, $tlen) === $tag) {
                    $inDollar = null;
                    $i += $tlen - 1;
                }
                continue;
            }

            // not inside any quote/comment
            // detect start of block comment /*
            if ($c === '/' && ($i + 1) < $len && $buf[$i + 1] === '*') {
                $inBlock = true;
                $i++;
                continue;
            }

            // detect line comment --
            if ($c === '-' && ($i + 1) < $len && $buf[$i + 1] === '-') {
                // skip until end of line
                $i += 2;
                while ($i < $len && $buf[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($c === "'") {
                $inSingle = true;
                continue;
            }
            if ($c === '"') {
                $inDouble = true;
                continue;
            }

            if ($c === '$') {
                // try to detect dollar-quote tag e.g. $tag$
                $rest = substr($buf, $i);
                if (preg_match('/^\$[A-Za-z0-9_]*\$/', $rest, $m)) {
                    $inDollar = $m[0];
                    $i += strlen($inDollar) - 1;
                    continue;
                }
            }

            // If a statement starts with COPY ... FROM stdin, capture until \.
            if ($i === $start) {
                // check for COPY at statement start
                if (preg_match('/^\s*COPY\b/i', substr($buf, $start))) {
                    // search for terminator pattern: "newline backslash dot newline" with optional CR
                    if (preg_match('/\r?\n\\\.\r?\n/', $buf, $m, PREG_OFFSET_CAPTURE, $start)) {
                        $pos = $m[0][1];
                        $matchLen = strlen($m[0][0]);
                        $stmt = substr($buf, $start, $pos + $matchLen - $start);
                        $statements[] = $stmt;
                        $start = $pos + $matchLen;
                        $i = $start - 1;
                        continue;
                    } else {
                        // need more data to complete COPY block
                        break;
                    }
                }
            }

            // semicolon ends statement when outside of quotes/comments
            if ($c === ';') {
                $stmt = substr($buf, $start, $i - $start + 1);
                $statements[] = $stmt;
                $start = $i + 1;
            }
        }

        $remainder = '';
        // If there's leftover after last complete statement, keep as remainder
        if ($start < $len) {
            $remainder = substr($buf, $start);
        }

        // consumed bytes from file = bytes read from file (not counting existingBuffer)
        $consumed = strlen($data);

        return ['statements' => $statements, 'consumed' => $consumed, 'eof' => $eof, 'remainder' => $remainder];
    }

    /**
     * Parse from a ReaderInterface instance. This mirrors parseChunk but reads
     * from the provided reader instead of directly from a file path.
     *
     * @param ReaderInterface $reader
     * @param int $maxBytes
     * @param string $existingBuffer
     * @return array
     */
    public static function parseFromReader($reader, int $maxBytes, string $existingBuffer = ''): array
    {
        $data = $reader->read($maxBytes);
        $eof = $reader->eof();

        $buf = $existingBuffer . $data;
        $len = strlen($buf);
        if ($len === 0) {
            return ['statements' => [], 'consumed' => 0, 'eof' => $eof, 'remainder' => $existingBuffer];
        }

        $statements = [];
        $start = 0;
        $inSingle = false;
        $inDouble = false;
        $inBlock = false;
        $inDollar = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $buf[$i];

            if ($inBlock) {
                if ($c === '*' && ($i + 1) < $len && $buf[$i + 1] === '/') {
                    $inBlock = false;
                    $i++;
                }
                continue;
            }

            if ($inSingle) {
                if ($c === "'") {
                    if (($i + 1) < $len && $buf[$i + 1] === "'") {
                        $i++;
                        continue;
                    }
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($c === '"') {
                    if (($i + 1) < $len && $buf[$i + 1] === '"') {
                        $i++;
                        continue;
                    }
                    $inDouble = false;
                }
                continue;
            }

            if ($inDollar !== null) {
                $tag = $inDollar;
                $tlen = strlen($tag);
                if ($tlen > 0 && substr($buf, $i, $tlen) === $tag) {
                    $inDollar = null;
                    $i += $tlen - 1;
                }
                continue;
            }

            if ($c === '/' && ($i + 1) < $len && $buf[$i + 1] === '*') {
                $inBlock = true;
                $i++;
                continue;
            }

            if ($c === '-' && ($i + 1) < $len && $buf[$i + 1] === '-') {
                $i += 2;
                while ($i < $len && $buf[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($c === "'") {
                $inSingle = true;
                continue;
            }
            if ($c === '"') {
                $inDouble = true;
                continue;
            }

            if ($c === '$') {
                $rest = substr($buf, $i);
                if (preg_match('/^\$[A-Za-z0-9_]*\$/', $rest, $m)) {
                    $inDollar = $m[0];
                    $i += strlen($inDollar) - 1;
                    continue;
                }
            }

            if ($i === $start) {
                if (preg_match('/^\s*COPY\b/i', substr($buf, $start))) {
                    if (preg_match('/\r?\n\\\.\r?\n/', $buf, $m, PREG_OFFSET_CAPTURE, $start)) {
                        $pos = $m[0][1];
                        $matchLen = strlen($m[0][0]);
                        $stmt = substr($buf, $start, $pos + $matchLen - $start);
                        $statements[] = $stmt;
                        $start = $pos + $matchLen;
                        $i = $start - 1;
                        continue;
                    } else {
                        break;
                    }
                }
            }

            if ($c === ';') {
                $stmt = substr($buf, $start, $i - $start + 1);
                $statements[] = $stmt;
                $start = $i + 1;
            }
        }

        $remainder = '';
        if ($start < $len) {
            $remainder = substr($buf, $start);
        }

        $consumed = strlen($data);

        return ['statements' => $statements, 'consumed' => $consumed, 'eof' => $eof, 'remainder' => $remainder];
    }
}
