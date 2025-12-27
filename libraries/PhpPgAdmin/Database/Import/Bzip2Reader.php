<?php
namespace PhpPgAdmin\Database\Import;

require_once __DIR__ . '/ReaderInterface.php';

class Bzip2Reader implements ReaderInterface
{
    protected $bz;
    protected $path;
    protected $pos = 0;

    public function __construct($path)
    {
        $this->path = $path;
        $this->bz = bzopen($path, 'r');
        if ($this->bz === false) {
            throw new \Exception("Unable to open bzip2 file: $path");
        }
    }

    public function read($length)
    {
        $data = bzread($this->bz, $length);
        $this->pos += ($data === false) ? 0 : strlen($data);
        return $data;
    }

    public function eof()
    {
        return feof($this->bz);
    }

    public function tell()
    {
        return $this->pos;
    }

    public function seek($offset)
    {
        if ($offset === $this->pos) {
            return true;
        }
        bzclose($this->bz);
        $this->bz = bzopen($this->path, 'r');
        if ($this->bz === false) {
            return false;
        }
        $remaining = $offset;
        $buf = 8192;
        while ($remaining > 0 && !feof($this->bz)) {
            $toRead = ($remaining > $buf) ? $buf : $remaining;
            $data = bzread($this->bz, $toRead);
            if ($data === false || $data === '') {
                break;
            }
            $remaining -= strlen($data);
        }
        $this->pos = $offset - $remaining;
        return ($remaining === 0);
    }

    public function close()
    {
        if ($this->bz !== null) {
            @bzclose($this->bz);
        }
    }
}
