<?php

namespace Ossrock\FflatePhp\Stream;

use Ossrock\FflatePhp\Checksums\CRC32;

class GzipStreamEncoder
{
    /** @var int */
    private $level;

    /** @var DeflateStream */
    private $def;

    /** @var bool */
    private $headerSent = false;

    /** @var int */
    private $crc = 0;

    /** @var int */
    private $size = 0;

    public function __construct($level = 6)
    {
        if ($level < 0 || $level > 9) {
            throw new \InvalidArgumentException('Compression level must be between 0 and 9');
        }
        $this->level = (int) $level;
        $this->def = new DeflateStream($this->level);
    }

    /**
     * @param string $chunk
     * @return string
     */
    public function append($chunk)
    {
        if ($chunk === '') {
            return '';
        }

        $out = '';
        if (!$this->headerSent) {
            $xfl = 0;
            if ($this->level === 1) {
                $xfl = 4;
            } elseif ($this->level === 9) {
                $xfl = 2;
            }
            $out .= "\x1f\x8b" . "\x08" . "\x00" . "\x00\x00\x00\x00" . chr($xfl) . "\xff";
            $this->headerSent = true;
        }

        $this->crc = CRC32::hash($chunk, $this->crc);
        $this->size = ($this->size + strlen($chunk)) & 0xFFFFFFFF;

        $out .= $this->def->append($chunk);
        return $out;
    }

    /**
     * Finish and return remaining bytes (including gzip footer).
     *
     * @return string
     */
    public function finish()
    {
        $out = '';
        if (!$this->headerSent) {
            // header even for empty
            $out .= "\x1f\x8b" . "\x08" . "\x00" . "\x00\x00\x00\x00" . "\x00" . "\xff";
            $this->headerSent = true;
        }

        $out .= $this->def->finish();
        $out .= pack('V', $this->crc & 0xFFFFFFFF) . pack('V', $this->size & 0xFFFFFFFF);
        return $out;
    }
}
