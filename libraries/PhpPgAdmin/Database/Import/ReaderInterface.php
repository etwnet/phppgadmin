<?php
namespace PhpPgAdmin\Database\Import;

/**
 * Reader interface for import streaming.
 */
interface ReaderInterface
{
    public function read($length);
    public function eof();
    public function tell();
    public function seek($offset);
    public function close();
}
