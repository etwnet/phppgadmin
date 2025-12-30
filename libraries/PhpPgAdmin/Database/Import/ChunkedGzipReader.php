<?php
namespace PhpPgAdmin\Database\Import;

/**
 * Reader for chunked gzip format where each chunk is an individual .gz file
 * This allows O(n) decompression performance instead of O(n²) for large files
 */
class ChunkedGzipReader implements ReaderInterface
{
    protected $jobDir;
    protected $chunks;  // Array of chunk metadata from state
    protected $currentChunkIndex = 0;
    protected $currentHandle = null;
    protected $logicalPosition = 0;  // Position in uncompressed stream across all chunks
    protected $eof = false;

    public function __construct($jobDir, $chunks)
    {
        if (!function_exists('gzopen')) {
            throw new \Exception('gzip support is not available (PHP ext-zlib)');
        }

        $this->jobDir = $jobDir;
        $this->chunks = $chunks;

        if (empty($this->chunks)) {
            $this->eof = true;
            return;
        }

        // Open first chunk
        $this->openChunk(0);
    }

    protected function openChunk($index)
    {
        if ($index >= count($this->chunks)) {
            $this->eof = true;
            return false;
        }

        // Close current chunk if open
        if ($this->currentHandle !== null) {
            gzclose($this->currentHandle);
            $this->currentHandle = null;
        }

        $chunk = $this->chunks[$index];
        $chunkPath = $this->jobDir . DIRECTORY_SEPARATOR . $chunk['file'];

        if (!file_exists($chunkPath)) {
            throw new \Exception("Chunk file not found: {$chunk['file']}");
        }

        $this->currentHandle = gzopen($chunkPath, 'rb');
        if ($this->currentHandle === false) {
            throw new \Exception("Unable to open chunk: {$chunk['file']}");
        }

        $this->currentChunkIndex = $index;
        return true;
    }

    public function read($length)
    {
        if ($this->eof) {
            return '';
        }

        $result = '';
        $remaining = $length;

        while ($remaining > 0 && !$this->eof) {
            if ($this->currentHandle === null) {
                $this->eof = true;
                break;
            }

            $data = gzread($this->currentHandle, $remaining);

            if ($data === false || $data === '') {
                // Current chunk exhausted, try next chunk
                if ($this->currentChunkIndex < count($this->chunks) - 1) {
                    $this->openChunk($this->currentChunkIndex + 1);
                    continue;
                } else {
                    $this->eof = true;
                    break;
                }
            }

            $result .= $data;
            $dataLen = strlen($data);
            $remaining -= $dataLen;
            $this->logicalPosition += $dataLen;
        }

        return $result;
    }

    public function eof()
    {
        return $this->eof;
    }

    public function tell()
    {
        return $this->logicalPosition;
    }

    public function seek($offset)
    {
        if ($offset === $this->logicalPosition) {
            return true;
        }

        // Binary search to find the chunk containing this offset
        $chunkIndex = $this->findChunkForOffset($offset);

        if ($chunkIndex === -1) {
            return false;
        }

        // Open the target chunk
        if (!$this->openChunk($chunkIndex)) {
            return false;
        }

        $chunk = $this->chunks[$chunkIndex];
        $chunkStartOffset = $chunk['uncompressed_offset'];
        $offsetInChunk = $offset - $chunkStartOffset;

        // Seek within the chunk by reading and discarding
        if ($offsetInChunk > 0) {
            $buf = 8192;
            $remaining = $offsetInChunk;
            while ($remaining > 0) {
                $toRead = ($remaining > $buf) ? $buf : $remaining;
                $data = gzread($this->currentHandle, $toRead);
                if ($data === false || $data === '') {
                    return false;
                }
                $remaining -= strlen($data);
            }
        }

        $this->logicalPosition = $offset;
        $this->eof = false;
        return true;
    }

    /**
     * Binary search to find which chunk contains the given offset
     * Returns chunk index or -1 if not found
     */
    protected function findChunkForOffset($offset)
    {
        $left = 0;
        $right = count($this->chunks) - 1;

        while ($left <= $right) {
            $mid = (int) (($left + $right) / 2);
            $chunk = $this->chunks[$mid];
            $chunkStart = $chunk['uncompressed_offset'];
            $chunkEnd = $chunkStart + $chunk['uncompressed_size'];

            if ($offset >= $chunkStart && $offset < $chunkEnd) {
                return $mid;
            } elseif ($offset < $chunkStart) {
                $right = $mid - 1;
            } else {
                $left = $mid + 1;
            }
        }

        return -1;
    }

    public function close()
    {
        if ($this->currentHandle !== null) {
            @gzclose($this->currentHandle);
            $this->currentHandle = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
