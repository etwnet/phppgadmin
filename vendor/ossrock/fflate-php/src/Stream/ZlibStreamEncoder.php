<?php

namespace Ossrock\FflatePhp\Stream;

use Ossrock\FflatePhp\Checksums\Adler32;

class ZlibStreamEncoder
{
    /** @var int */
    private $level;

    /** @var DeflateStream */
    private $def;

    /** @var bool */
    private $headerSent = false;

    /** @var int */
    private $adler = 1;

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
            // CMF 0x78, FLG preset satisfying FCHECK.
            if ($this->level <= 1) {
                $out .= "\x78\x01";
            } elseif ($this->level <= 5) {
                $out .= "\x78\x5E";
            } elseif ($this->level === 6) {
                $out .= "\x78\x9C";
            } else {
                $out .= "\x78\xDA";
            }
            $this->headerSent = true;
        }

        $this->adler = Adler32::hash($chunk, $this->adler);
        $out .= $this->def->append($chunk);
        return $out;
    }

    /**
     * @return string
     */
    public function finish()
    {
        $out = '';
        if (!$this->headerSent) {
            $out .= "\x78\x9C";
            $this->headerSent = true;
        }
        $out .= $this->def->finish();
        $out .= pack('N', $this->adler & 0xFFFFFFFF);
        return $out;
    }
}
