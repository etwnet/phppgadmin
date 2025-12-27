<?php

namespace PhpPgAdmin\Database\Import;

abstract class InputParser
{
    protected $stream;

    public function setStream($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Parse up to a complete set of statements or data chunks.
     * Returns array with keys: statements (array), eof (bool), consumedBytes (int)
     */
    abstract public function parseChunk(int $maxBytes): array;
}
