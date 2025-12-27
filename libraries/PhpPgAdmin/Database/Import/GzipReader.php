<?php
namespace PhpPgAdmin\Database\Import;

require_once __DIR__ . '/ReaderInterface.php';

class GzipReader implements ReaderInterface
{
    protected $gz;
    protected $pos = 0;

    public function __construct($path)
    {
        if (!function_exists('gzopen')) {
            throw new \Exception('gzip support is not available (PHP ext-zlib)');
        }
        $gzopen = 'gzopen';
        $this->gz = $gzopen($path, 'rb');
        if ($this->gz === false) {
            throw new \Exception("Unable to open gzip file: $path");
        }
    }

    public function read($length)
    {
        $gzread = 'gzread';
        $data = $gzread($this->gz, $length);
        $this->pos += ($data === false) ? 0 : strlen($data);
        return $data;
    }

    public function eof()
    {
        $gzeof = 'gzeof';
        return $gzeof($this->gz);
    }

    public function tell()
    {
        if (function_exists('gztell')) {
            return gztell($this->gz);
        }
        return $this->pos;
    }

    public function seek($offset)
    {
        if (function_exists('gzseek')) {
            $res = gzseek($this->gz, $offset);
            if ($res === 0) {
                $this->pos = $offset;
                return true;
            }
            return false;
        }
        return false;
    }

    public function close()
    {
        if ($this->gz !== null) {
            if (function_exists('gzclose')) {
                $gzclose = 'gzclose';
                @$gzclose($this->gz);
            }
        }
    }
}
