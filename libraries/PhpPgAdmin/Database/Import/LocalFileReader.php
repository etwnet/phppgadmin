<?php
namespace PhpPgAdmin\Database\Import;

require_once __DIR__ . '/ReaderInterface.php';

class LocalFileReader implements ReaderInterface
{
    protected $fp;

    public function __construct($path)
    {
        $this->fp = fopen($path, 'rb');
        if ($this->fp === false) {
            throw new \Exception("Unable to open file: $path");
        }
    }

    public function read($length)
    {
        return fread($this->fp, $length);
    }

    public function eof()
    {
        return feof($this->fp);
    }

    public function tell()
    {
        return ftell($this->fp);
    }

    public function seek($offset)
    {
        return fseek($this->fp, $offset, SEEK_SET) === 0;
    }

    public function close()
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
    }
}
