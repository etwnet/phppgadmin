<?php
namespace PhpPgAdmin\Database\Import;

require_once __DIR__ . '/ReaderInterface.php';

class ZipEntryReader implements ReaderInterface
{
    protected $zipPath;
    protected $entryName;
    protected $zip;
    protected $stream;
    protected $pos = 0;

    public function __construct($zipPath, $entryName)
    {
        $this->zipPath = $zipPath;
        $this->entryName = $entryName;
        $this->openStream();
    }

    protected function openStream()
    {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('zip support is not available (PHP ext-zip / ZipArchive)');
        }
        $this->zip = new \ZipArchive();
        if ($this->zip->open($this->zipPath) !== true) {
            throw new \Exception("Unable to open zip: {$this->zipPath}");
        }
        $this->stream = $this->zip->getStream($this->entryName);
        if ($this->stream === false) {
            $this->zip->close();
            throw new \Exception("Unable to open zip entry: {$this->entryName}");
        }
        $this->pos = 0;
    }

    public function read($length)
    {
        $data = fread($this->stream, $length);
        if ($data === false) {
            return '';
        }
        $this->pos += strlen($data);
        return $data;
    }

    public function eof()
    {
        if (!is_resource($this->stream)) {
            return true;
        }
        return feof($this->stream);
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
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        if ($this->zip !== null) {
            $this->zip->close();
        }
        $this->openStream();
        $remaining = $offset;
        $buf = 8192;
        while ($remaining > 0 && !feof($this->stream)) {
            $toRead = ($remaining > $buf) ? $buf : $remaining;
            $data = fread($this->stream, $toRead);
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
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        if ($this->zip !== null) {
            $this->zip->close();
        }
    }
}
