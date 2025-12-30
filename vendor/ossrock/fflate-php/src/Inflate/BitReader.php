<?php

namespace Ossrock\FflatePhp\Inflate;

/**
 * Bit-level reader for DEFLATE streams
 */
class BitReader
{
    /** @var string Binary data */
    private $data;

    /** @var int Data length in bytes */
    private $length;

    /** @var int Current bit position */
    private $pos;

    /**
     * @param string $data Binary data to read from
     * @param int $bitPos Starting bit position (default: 0)
     */
    public function __construct($data, $bitPos = 0)
    {
        $this->data = $data;
        $this->length = strlen($data);
        $this->pos = $bitPos;
    }

    /**
     * Read bits from the stream (does NOT advance position)
     *
     * @param int $count Number of bits to read (max 16)
     * @return int Value read
     */
    public function readBits($count)
    {
        $bytePos = (int) ($this->pos / 8);
        $bitOffset = $this->pos & 7;

        if ($bytePos >= $this->length) {
            return 0;
        }

        if ($count <= 0) {
            return 0;
        }

        // Read 3 bytes (24 bits) so unaligned reads up to 16 bits are safe
        // (e.g. bitOffset=7, count=13 needs 20 bits of backing data).
        $byte1 = ord($this->data[$bytePos]);
        $byte2 = $bytePos + 1 < $this->length ? ord($this->data[$bytePos + 1]) : 0;
        $byte3 = $bytePos + 2 < $this->length ? ord($this->data[$bytePos + 2]) : 0;

        $value = ($byte1 | ($byte2 << 8) | ($byte3 << 16)) >> $bitOffset;
        $mask = (1 << $count) - 1;

        return $value & $mask;
    }

    /**
     * Read bits and advance position
     *
     * @param int $count Number of bits to read (max 16)
     * @return int Value read
     */
    public function readBitsAdvance($count)
    {
        $value = $this->readBits($count);
        $this->pos += $count;
        return $value;
    }

    /**
     * Read at least 16 bits from the stream
     *
     * @return int Value read (up to 24 bits)
     */
    public function readBits16()
    {
        $bytePos = (int) ($this->pos / 8);
        $bitOffset = $this->pos & 7;

        if ($bytePos >= $this->length) {
            return 0;
        }

        // Read 3 bytes (24 bits) for safe 16-bit reads
        $byte1 = ord($this->data[$bytePos]);
        $byte2 = $bytePos + 1 < $this->length ? ord($this->data[$bytePos + 1]) : 0;
        $byte3 = $bytePos + 2 < $this->length ? ord($this->data[$bytePos + 2]) : 0;

        return ($byte1 | ($byte2 << 8) | ($byte3 << 16)) >> $bitOffset;
    }

    /**
     * Read a byte-aligned byte from the stream
     *
     * @return int Byte value (0-255)
     */
    public function readByte()
    {
        $bytePos = (int) ($this->pos / 8);
        if ($bytePos >= $this->length) {
            return 0;
        }
        $this->pos += 8;
        return ord($this->data[$bytePos]);
    }

    /**
     * Read multiple bytes from the stream
     *
     * @param int $count Number of bytes to read
     * @return string Binary string
     */
    public function readBytes($count)
    {
        $bytePos = (int) ($this->pos / 8);
        if ($bytePos >= $this->length) {
            return '';
        }

        $this->pos += $count * 8;
        return substr($this->data, $bytePos, $count);
    }

    /**
     * Skip to the next byte boundary
     */
    public function alignByte()
    {
        if ($this->pos & 7) {
            $this->pos = ((int) ($this->pos / 8) + 1) * 8;
        }
    }

    /**
     * Get current bit position
     *
     * @return int Bit position
     */
    public function getPosition()
    {
        return $this->pos;
    }

    /**
     * Set bit position
     *
     * @param int $pos New bit position
     */
    public function setPosition($pos)
    {
        $this->pos = $pos;
    }

    /**
     * Check if there's more data to read
     *
     * @param int $bits Number of bits needed
     * @return bool True if enough data available
     */
    public function hasData($bits = 8)
    {
        return ($this->pos + $bits) <= ($this->length * 8);
    }

    /**
     * Get underlying data
     *
     * @return string Binary data
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Get data length in bytes
     *
     * @return int Length in bytes
     */
    public function getLength()
    {
        return $this->length;
    }
}
