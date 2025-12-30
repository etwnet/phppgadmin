<?php

namespace Ossrock\FflatePhp\Stream;

use Ossrock\FflatePhp\Deflate\BitWriter;
use Ossrock\FflatePhp\Deflate\Deflate;

/**
 * Incremental DEFLATE stream writer.
 *
 * Notes:
 * - Level 0 streams stored blocks.
 * - Level 1-9 streams fixed-Huffman blocks.
 * - For simplicity, matches are not carried across blocks (dictionary resets per block).
 */
class DeflateStream
{
    /** @var int */
    private $level;

    /** @var BitWriter */
    private $bw;

    /** @var bool */
    private $started = false;

    public function __construct($level = 6)
    {
        if ($level < 0 || $level > 9) {
            throw new \InvalidArgumentException('Compression level must be between 0 and 9');
        }
        $this->level = (int) $level;
        $this->bw = new BitWriter(65536);
    }

    /**
     * Append data and return newly available compressed bytes.
     *
     * @param string $chunk
     * @return string
     */
    public function append($chunk)
    {
        if ($chunk === '') {
            return '';
        }

        $this->started = true;

        if ($this->level === 0) {
            // Stored blocks can be emitted chunk-by-chunk, fully byte-aligned.
            Deflate::writeStoredBlocks($this->bw, $chunk, false);
            return $this->bw->flush(true);
        }

        // Fixed Huffman block, non-final.
        Deflate::writeFixedBlock($this->bw, $chunk, $this->level, false);
        // Only flush full bytes; keep partial bit byte for next block.
        return $this->bw->flush(false);
    }

    /**
     * Finish the stream and return remaining bytes.
     *
     * @return string
     */
    public function finish()
    {
        if ($this->level === 0) {
            // Terminate with an empty final stored block
            Deflate::writeStoredBlocks($this->bw, '', true);
            return $this->bw->flush(true);
        }

        // Final empty fixed block (EOB only)
        Deflate::writeFixedBlock($this->bw, '', $this->level, true);
        return $this->bw->flush(true);
    }
}
