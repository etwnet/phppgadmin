<?php

namespace Ossrock\FflatePhp\Deflate;

/**
 * Bit-level writer for DEFLATE streams
 */
class BitWriter
{
    /** @var string Binary output buffer */
    private $buffer;

    /** @var int Current bit position */
    private $pos;

    /** @var int Buffer size in bytes */
    private $size;

    /** @var int Number of full bytes already flushed */
    private $flushed;

    /**
     * @param int $initialSize Initial buffer size in bytes
     */
    public function __construct($initialSize = 65536)
    {
        $this->buffer = str_repeat("\0", $initialSize);
        $this->pos = 0;
        $this->size = $initialSize;
        $this->flushed = 0;
    }

    /**
     * Write bits to the stream
     *
     * @param int $value Value to write
     * @param int $count Number of bits to write
     */
    public function writeBits($value, $count)
    {
        $bytePos = (int) ($this->pos / 8);
        $bitOffset = $this->pos & 7;

        // Ensure buffer has enough space
        $this->ensureSize($bytePos + 3);

        // Shift value to align with bit position
        $value = ($value << $bitOffset) & 0xFFFFFFFF;

        // Write to buffer (up to 3 bytes for safety)
        $this->buffer[$bytePos] = chr(ord($this->buffer[$bytePos]) | ($value & 0xFF));

        if ($bytePos + 1 < $this->size) {
            $this->buffer[$bytePos + 1] = chr(ord($this->buffer[$bytePos + 1]) | (($value >> 8) & 0xFF));
        }

        if ($bytePos + 2 < $this->size) {
            $this->buffer[$bytePos + 2] = chr(ord($this->buffer[$bytePos + 2]) | (($value >> 16) & 0xFF));
        }

        $this->pos += $count;
    }

    /**
     * Write 16 or more bits to the stream
     *
     * @param int $value Value to write
     * @param int $count Number of bits to write
     */
    public function writeBits16($value, $count)
    {
        $this->writeBits($value, $count);
    }

    /**
     * Write a byte to the stream
     *
     * @param int $value Byte value (0-255)
     */
    public function writeByte($value)
    {
        $bytePos = (int) ($this->pos / 8);
        $this->ensureSize($bytePos + 1);
        $this->buffer[$bytePos] = chr($value & 0xFF);
        $this->pos += 8;
    }

    /**
     * Write multiple bytes to the stream
     *
     * @param string $data Binary data to write
     */
    public function writeBytes($data)
    {
        $len = strlen($data);
        $bytePos = (int) ($this->pos / 8);
        $this->ensureSize($bytePos + $len);

        for ($i = 0; $i < $len; ++$i) {
            $this->buffer[$bytePos + $i] = $data[$i];
        }

        $this->pos += $len * 8;
    }

    /**
     * Align to the next byte boundary
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
     * Get the output buffer
     *
     * @return string Binary data up to current position
     */
    public function getBuffer()
    {
        $byteLength = (int) (($this->pos + 7) / 8);
        return substr($this->buffer, 0, $byteLength);
    }

    /**
     * Flush newly written bytes.
     *
     * When $final is false, this returns only full bytes (keeps any partial byte
     * in the internal buffer so more bits can be appended later).
     *
     * @param bool $final
     * @return string
     */
    public function flush($final = false)
    {
        $byteLength = $final ? (int) (($this->pos + 7) / 8) : (int) ($this->pos / 8);
        if ($byteLength <= $this->flushed) {
            return '';
        }
        $out = substr($this->buffer, $this->flushed, $byteLength - $this->flushed);
        $this->flushed = $byteLength;
        return $out;
    }

    /**
     * Ensure buffer has enough space
     *
     * @param int $requiredBytes Required size in bytes
     */
    private function ensureSize($requiredBytes)
    {
        if ($requiredBytes > $this->size) {
            $newSize = max($this->size * 2, $requiredBytes + 1024);
            $this->buffer .= str_repeat("\0", $newSize - $this->size);
            $this->size = $newSize;
        }
    }

    /**
     * Reset the writer
     */
    public function reset()
    {
        $this->buffer = str_repeat("\0", $this->size);
        $this->pos = 0;
        $this->flushed = 0;
    }
}
