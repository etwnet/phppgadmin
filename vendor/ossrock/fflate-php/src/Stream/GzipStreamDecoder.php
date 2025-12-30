<?php

namespace Ossrock\FflatePhp\Stream;

use Ossrock\FflatePhp\Checksums\CRC32;
use Ossrock\FflatePhp\Inflate\NeedMoreDataException;

class GzipStreamDecoder
{
    /** @var string */
    private $buffer = '';

    /** @var bool */
    private $headerParsed = false;

    /** @var InflateStream */
    private $inf;

    /** @var int */
    private $crc = 0;

    /** @var int */
    private $size = 0;

    /** @var bool */
    private $done = false;

    public function __construct()
    {
        $this->inf = new InflateStream();
    }

    /**
     * Append gzip bytes and return decoded bytes.
     *
     * @param string $data
     * @param bool $closing
     * @return string
     */
    public function append($data, $closing = false)
    {
        if ($this->done) {
            return '';
        }

        $this->buffer .= $data;
        $out = '';

        while (true) {
            if (!$this->headerParsed) {
                $hdr = $this->tryParseHeader($this->buffer);
                if ($hdr === null) {
                    break;
                }
                if ($hdr === false) {
                    trigger_error('invalid gzip data', E_USER_WARNING);
                    $this->done = true;
                    break;
                }
                $this->headerParsed = true;
                $this->buffer = substr($this->buffer, $hdr);
            }

            if ($this->buffer !== '') {
                $produced = $this->inf->append($this->buffer);
                $this->buffer = '';
                if ($produced !== '') {
                    $this->crc = CRC32::hash($produced, $this->crc);
                    $this->size = ($this->size + strlen($produced)) & 0xFFFFFFFF;
                    $out .= $produced;
                }
            }

            if ($this->inf->isDone()) {
                // Footer bytes (and possibly next member) live in the inflater remainder.
                $this->buffer .= $this->inf->drainRemainderBytes();

                if (strlen($this->buffer) < 8) {
                    // Need more for footer
                    break;
                }

                $crcExpected = unpack('V', substr($this->buffer, 0, 4))[1] & 0xFFFFFFFF;
                $sizeExpected = unpack('V', substr($this->buffer, 4, 4))[1] & 0xFFFFFFFF;

                if (($this->crc & 0xFFFFFFFF) !== $crcExpected) {
                    trigger_error('gzip CRC32 mismatch', E_USER_WARNING);
                    $this->done = true;
                    break;
                }
                if (($this->size & 0xFFFFFFFF) !== $sizeExpected) {
                    trigger_error('gzip size mismatch', E_USER_WARNING);
                    $this->done = true;
                    break;
                }

                // Consume footer
                $this->buffer = substr($this->buffer, 8);

                // Concatenated member support: if more bytes exist, start next member.
                if ($this->buffer !== '') {
                    $this->resetForNextMember();
                    continue;
                }

                $this->done = true;
                break;
            }

            // Need more input to progress
            break;
        }

        if ($closing) {
            // When closing, require either a clean end or explicitly treat as invalid.
            if (!$this->done) {
                trigger_error('invalid gzip data', E_USER_WARNING);
                $this->done = true;
            }
        }

        return $out;
    }

    private function resetForNextMember()
    {
        $this->headerParsed = false;
        $this->inf = new InflateStream();
        $this->crc = 0;
        $this->size = 0;
        $this->done = false;
    }

    /**
     * @param string $data
     * @return int|false|null Header end offset; null means need more data.
     */
    private function tryParseHeader($data)
    {
        $len = strlen($data);
        if ($len < 10) {
            return null;
        }

        if (ord($data[0]) !== 31 || ord($data[1]) !== 139 || ord($data[2]) !== 8) {
            return false;
        }

        $flags = ord($data[3]);
        $pos = 10;

        if ($flags & 4) {
            if ($len < $pos + 2) {
                return null;
            }
            $xlen = ord($data[$pos]) | (ord($data[$pos + 1]) << 8);
            $pos += 2;
            if ($len < $pos + $xlen) {
                return null;
            }
            $pos += $xlen;
        }

        if ($flags & 8) {
            while (true) {
                if ($pos >= $len) {
                    return null;
                }
                if (ord($data[$pos]) === 0) {
                    $pos++;
                    break;
                }
                $pos++;
            }
        }

        if ($flags & 16) {
            while (true) {
                if ($pos >= $len) {
                    return null;
                }
                if (ord($data[$pos]) === 0) {
                    $pos++;
                    break;
                }
                $pos++;
            }
        }

        if ($flags & 2) {
            if ($len < $pos + 2) {
                return null;
            }
            $pos += 2;
        }

        if ($pos > $len) {
            return null;
        }

        return $pos;
    }
}
