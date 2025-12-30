<?php

namespace Ossrock\FflatePhp\Stream;

use Ossrock\FflatePhp\FflateException;
use Ossrock\FflatePhp\Inflate\BitReader;
use Ossrock\FflatePhp\Inflate\Huffman;
use Ossrock\FflatePhp\Inflate\NeedMoreDataException;
use Ossrock\FflatePhp\Inflate\Tables;

/**
 * Incremental DEFLATE inflater.
 *
 * Produces decompressed bytes as input arrives and keeps only a 32KB sliding
 * window for LZ77 back-references.
 */
class InflateStream
{
    /** @var string */
    private $input = '';

    /** @var int */
    private $posBits = 0;

    /** @var int */
    private $bytesDropped = 0;

    /** @var bool */
    private $final = false;

    /** @var array|null */
    private $lmap;

    /** @var array|null */
    private $dmap;

    /** @var int */
    private $lbits = 0;

    /** @var int */
    private $dbits = 0;

    /** @var string */
    private $window = '';

    /** @var bool */
    private $done = false;

    public function __construct()
    {
        Tables::init();
        $this->lmap = null;
        $this->dmap = null;
    }

    /**
     * Append compressed data and return newly produced output.
     *
     * @param string $data
     * @return string
     */
    public function append($data)
    {
        if ($this->done || $data === '') {
            return '';
        }
        $this->input .= $data;
        return $this->pump();
    }

    /**
     * Finish the stream (expects no more data); returns remaining output.
     *
     * @return string
     */
    public function finish()
    {
        if ($this->done) {
            return '';
        }
        $out = $this->pump();
        // If not done after attempting to pump, caller likely didn't provide enough bytes.
        return $out;
    }

    /**
     * Whether the DEFLATE stream reached end-of-block for the final block.
     */
    public function isDone()
    {
        return $this->done;
    }

    /**
     * Total number of bytes consumed from the beginning of the stream.
     *
     * When done, this is the byte offset of the first byte after the deflate stream.
     */
    public function getTotalConsumedBytes()
    {
        // When we need to locate the boundary (e.g., gzip footer), deflate ends at the
        // next byte boundary after the last consumed bit.
        return $this->bytesDropped + (int) (($this->posBits + 7) / 8);
    }

    /**
     * Return bytes after the DEFLATE stream end (byte-aligned) and clear internal input.
     *
     * This should only be called once isDone() is true.
     *
     * @return string
     */
    public function drainRemainderBytes()
    {
        $bytePos = (int) (($this->posBits + 7) / 8);
        $rem = ($bytePos <= 0) ? $this->input : substr($this->input, $bytePos);
        $this->input = '';
        $this->posBits = 0;
        $this->bytesDropped = 0;
        return $rem;
    }

    private function pump()
    {
        $reader = new BitReader($this->input, $this->posBits);
        $out = '';

        try {
            while (true) {
                if ($this->lmap === null) {
                    // Need block header
                    $this->needBits($reader, 3);
                    $this->final = $reader->readBitsAdvance(1) === 1;
                    $type = $reader->readBitsAdvance(2);

                    if ($type === 0) {
                        // Stored
                        $reader->alignByte();
                        $this->needBits($reader, 32);
                        $len = $reader->readByte() | ($reader->readByte() << 8);
                        $nlen = $reader->readByte() | ($reader->readByte() << 8);
                        if (($len ^ $nlen) !== 0xFFFF) {
                            throw FflateException::create(FflateException::INVALID_LENGTH_LITERAL);
                        }
                        $this->needBits($reader, $len * 8);
                        $chunk = $reader->readBytes($len);
                        $this->emit($chunk, $out);
                        // Continue to next block
                        continue;
                    }

                    if ($type === 1) {
                        $fixed = Huffman::buildFixedTables();
                        $this->lmap = $fixed['lmap'];
                        $this->dmap = $fixed['dmap'];
                        $this->lbits = $fixed['lbits'];
                        $this->dbits = $fixed['dbits'];
                    } elseif ($type === 2) {
                        $posBefore = $reader->getPosition();
                        try {
                            $dyn = $this->readDynamicTables($reader);
                            $this->lmap = $dyn['lmap'];
                            $this->dmap = $dyn['dmap'];
                            $this->lbits = $dyn['lbits'];
                            $this->dbits = $dyn['dbits'];
                        } catch (NeedMoreDataException $e) {
                            // rewind and ask for more
                            $reader->setPosition($posBefore);
                            throw $e;
                        }
                    } else {
                        throw FflateException::create(FflateException::INVALID_BLOCK_TYPE);
                    }
                }

                // Decode block symbols
                $lmask = (1 << $this->lbits) - 1;
                $dmask = (1 << $this->dbits) - 1;

                while (true) {
                    $this->needBits($reader, $this->lbits);
                    $pos0 = $reader->getPosition();
                    $bits = $reader->readBits16();
                    $entry = $this->lmap[$bits & $lmask];
                    if ($entry === 0) {
                        throw FflateException::create(FflateException::INVALID_LENGTH_LITERAL);
                    }
                    $sym = $entry >> 4;
                    $clen = $entry & 15;
                    $reader->setPosition($pos0 + $clen);

                    if ($sym < 256) {
                        $this->emit(chr($sym), $out);
                        continue;
                    }

                    if ($sym === 256) {
                        // End of block
                        $this->lmap = null;
                        $this->dmap = null;
                        $this->lbits = 0;
                        $this->dbits = 0;
                        if ($this->final) {
                            $this->done = true;
                        }
                        break;
                    }

                    // Length
                    $length = $sym - 254;
                    if ($sym > 264) {
                        $i = $sym - 257;
                        $extra = Tables::$FLEB[$i];
                        if ($extra) {
                            $this->needBits($reader, $extra);
                        }
                        $length = Tables::$FL[$i] + ($extra ? $reader->readBitsAdvance($extra) : 0);
                    }

                    // Distance symbol
                    $this->needBits($reader, $this->dbits);
                    $pos1 = $reader->getPosition();
                    $bits2 = $reader->readBits16();
                    $dentry = $this->dmap[$bits2 & $dmask];
                    if ($dentry === 0) {
                        throw FflateException::create(FflateException::INVALID_DISTANCE);
                    }
                    $dsym = $dentry >> 4;
                    $dlen = $dentry & 15;
                    $reader->setPosition($pos1 + $dlen);

                    $distance = Tables::$FD[$dsym];
                    if ($dsym > 3) {
                        $extra = Tables::$FDEB[$dsym];
                        if ($extra) {
                            $this->needBits($reader, $extra);
                        }
                        $distance += $extra ? $reader->readBitsAdvance($extra) : 0;
                    }

                    $this->copyFromWindow($distance, $length, $out);
                }

                if ($this->done) {
                    break;
                }
            }
        } finally {
            $this->posBits = $reader->getPosition();
            $this->compactInput();
        }

        return $out;
    }

    private function needBits(BitReader $reader, $bits)
    {
        if (!$reader->hasData($bits)) {
            throw new NeedMoreDataException('need more data');
        }
    }

    private function emit($bytes, &$out)
    {
        if ($bytes === '') {
            return;
        }
        $out .= $bytes;
        $this->window .= $bytes;
        if (strlen($this->window) > 32768) {
            $this->window = substr($this->window, -32768);
        }
    }

    private function copyFromWindow($distance, $length, &$out)
    {
        $wlen = strlen($this->window);
        if ($distance < 1 || $distance > $wlen) {
            throw FflateException::create(FflateException::INVALID_DISTANCE);
        }

        // Important: do not trim the shared window during the copy loop.
        // Trimming shifts indices and would corrupt overlapping copies.
        $window = $this->window;
        $start = $wlen - $distance;
        $chunk = '';

        for ($i = 0; $i < $length; $i++) {
            $b = $window[$start + $i];
            $chunk .= $b;
            $window .= $b;
        }

        $out .= $chunk;
        $this->window = (strlen($window) > 32768) ? substr($window, -32768) : $window;
    }

    /**
     * Dynamic table reader with streaming checks.
     *
     * @return array{lmap: array, dmap: array, lbits: int, dbits: int}
     */
    private function readDynamicTables(BitReader $reader)
    {
        $this->needBits($reader, 14);
        $hlit = $reader->readBitsAdvance(5) + 257;
        $hdist = $reader->readBitsAdvance(5) + 1;
        $hclen = $reader->readBitsAdvance(4) + 4;

        $total = $hlit + $hdist;

        $codeLengthLengths = array_fill(0, 19, 0);
        for ($i = 0; $i < $hclen; $i++) {
            $this->needBits($reader, 3);
            $codeLengthLengths[Tables::$CLIM[$i]] = $reader->readBitsAdvance(3);
        }

        $clbits = Huffman::max($codeLengthLengths);
        $clmap = Huffman::buildMap($codeLengthLengths, $clbits, true);
        $clmask = (1 << $clbits) - 1;

        $codeLengths = [];
        $i = 0;
        while ($i < $total) {
            $this->needBits($reader, $clbits);
            $pos0 = $reader->getPosition();
            $bits = $reader->readBits16();
            $entry = $clmap[$bits & $clmask];
            if ($entry === 0) {
                throw FflateException::create(FflateException::INVALID_LENGTH_LITERAL);
            }
            $sym = $entry >> 4;
            $len = $entry & 15;
            $reader->setPosition($pos0 + $len);

            if ($sym < 16) {
                $codeLengths[$i++] = $sym;
                continue;
            }

            if ($sym === 16) {
                $this->needBits($reader, 2);
                $repeat = 3 + $reader->readBitsAdvance(2);
                $value = $codeLengths[$i - 1];
            } elseif ($sym === 17) {
                $this->needBits($reader, 3);
                $repeat = 3 + $reader->readBitsAdvance(3);
                $value = 0;
            } else {
                $this->needBits($reader, 7);
                $repeat = 11 + $reader->readBitsAdvance(7);
                $value = 0;
            }

            while ($repeat-- > 0) {
                $codeLengths[$i++] = $value;
            }
        }

        $litLens = array_slice($codeLengths, 0, $hlit);
        $distLens = array_slice($codeLengths, $hlit);

        $lbits = Huffman::max($litLens);
        $dbits = Huffman::max($distLens);

        $lmap = Huffman::buildMap($litLens, $lbits, true);
        $dmap = Huffman::buildMap($distLens, $dbits, true);

        return ['lmap' => $lmap, 'dmap' => $dmap, 'lbits' => $lbits, 'dbits' => $dbits];
    }

    private function compactInput()
    {
        $drop = (int) ($this->posBits / 8);
        if ($drop <= 0) {
            return;
        }

        // Drop fully consumed bytes.
        $this->input = substr($this->input, $drop);
        $this->bytesDropped += $drop;
        $this->posBits -= $drop * 8;
    }
}
