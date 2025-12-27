<?php
namespace PhpPgAdmin\Database\Import;

require_once __DIR__ . '/ReaderInterface.php';

class Bzip2Reader implements ReaderInterface
{
    protected $bz;
    protected $path;
    protected $pos = 0;
    protected $eof = false;

    public function __construct($path)
    {
        $this->path = $path;
        if (!function_exists('bzopen')) {
            throw new \Exception('bzip2 support is not available (PHP ext-bz2)');
        }
        $bzopen = 'bzopen';
        $this->bz = $bzopen($path, 'r');
        if ($this->bz === false) {
            throw new \Exception("Unable to open bzip2 file: $path");
        }
    }

    public function read($length)
    {
        if ($this->eof) {
            return '';
        }
        $bzread = 'bzread';
        $data = $bzread($this->bz, $length);
        if ($data === false || $data === '') {
            $this->eof = true;
            return '';
        }
        $this->pos += strlen($data);
        return $data;
    }

    public function eof()
    {
        return $this->eof;
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

        $bzclose = 'bzclose';
        $bzopen = 'bzopen';
        $bzread = 'bzread';

        $bzclose($this->bz);
        $this->bz = $bzopen($this->path, 'r');
        if ($this->bz === false) {
            return false;
        }
        $this->eof = false;

        $remaining = $offset;
        $buf = 8192;
        while ($remaining > 0 && !$this->eof) {
            $toRead = ($remaining > $buf) ? $buf : $remaining;
            $data = $bzread($this->bz, $toRead);
            if ($data === false || $data === '') {
                $this->eof = true;
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
            if (function_exists('bzclose')) {
                $bzclose = 'bzclose';
                @$bzclose($this->bz);
            }
        }
    }
}
