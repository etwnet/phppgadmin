<?php

namespace Ossrock\FflatePhp\Stream;

use Ossrock\FflatePhp\Checksums\Adler32;
use Ossrock\FflatePhp\Inflate\NeedMoreDataException;

class ZlibStreamDecoder
{
    /** @var string */
    private $buffer = '';

    /** @var bool */
    private $headerParsed = false;

    /** @var InflateStream */
    private $inf;

    /** @var int */
    private $adler = 1;

    /** @var bool */
    private $done = false;

    public function __construct()
    {
        $this->inf = new InflateStream();
    }

    /**
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
                    trigger_error('invalid zlib data', E_USER_WARNING);
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
                    $this->adler = Adler32::hash($produced, $this->adler);
                    $out .= $produced;
                }
            }

            if ($this->inf->isDone()) {
                $this->buffer .= $this->inf->drainRemainderBytes();
                if (strlen($this->buffer) < 4) {
                    break;
                }

                $expected = unpack('N', substr($this->buffer, 0, 4))[1] & 0xFFFFFFFF;
                if (($this->adler & 0xFFFFFFFF) !== $expected) {
                    trigger_error('zlib Adler32 mismatch', E_USER_WARNING);
                    $this->done = true;
                    break;
                }

                $this->buffer = substr($this->buffer, 4);
                $this->done = true;
                break;
            }

            break;
        }

        if ($closing && !$this->done) {
            trigger_error('invalid zlib data', E_USER_WARNING);
            $this->done = true;
        }

        return $out;
    }

    /**
     * @param string $data
     * @return int|false|null Bytes to consume for header; null if need more.
     */
    private function tryParseHeader($data)
    {
        $len = strlen($data);
        if ($len < 2) {
            return null;
        }

        $cmf = ord($data[0]);
        $flg = ord($data[1]);
        if (($cmf & 0x0F) !== 8) {
            return false;
        }
        if (((($cmf << 8) | $flg) % 31) !== 0) {
            return false;
        }

        $fdict = ($flg & 0x20) !== 0;
        $pos = 2;
        if ($fdict) {
            if ($len < 6) {
                return null;
            }
            $pos += 4;
        }
        return $pos;
    }
}
