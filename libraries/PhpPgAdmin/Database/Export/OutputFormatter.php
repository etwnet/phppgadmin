<?php

namespace PhpPgAdmin\Database\Export;

/**
 * Abstract base class for export output formatters.
 * Each formatter is responsible for transforming dumped data into a specific output format.
 */
abstract class OutputFormatter
{
    /**
     * The MIME type for this format (e.g., 'text/plain', 'text/csv')
     * @var string
     */
    protected $mimeType = 'text/plain; charset=utf-8';

    /**
     * The file extension for this format (e.g., 'sql', 'csv', 'json')
     * @var string
     */
    protected $fileExtension = 'sql';

    /**
     * Whether gzip compression is supported for this format
     * @var bool
     */
    protected $supportsGzip = true;

    /**
     * Output stream for writing formatted data
     * @var resource|null
     */
    protected $outputStream = null;

    /**
     * Get the MIME type for this format
     * @return string
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * Get the file extension for this format
     * @return string
     */
    public function getFileExtension()
    {
        return $this->fileExtension;
    }

    /**
     * Check if gzip compression is supported
     * @return bool
     */
    public function supportsGzip()
    {
        return $this->supportsGzip;
    }

    /**
     * Set the output stream for writing formatted data.
     * If not set (null), format() will collect and return output as string.
     *
     * @param resource|null $stream File handle or stream resource, or null to collect as string
     */
    public function setOutputStream($stream)
    {
        $this->outputStream = $stream;
    }

    /**
     * Write data to output stream or accumulate as string.
     * If output stream is set, writes to it and returns empty string.
     * Otherwise, accumulates and returns the string for collection.
     *
     * @param string $data Data to write
     * @return string Empty string if stream is set, otherwise accumulated string
     */
    protected function write($data)
    {
        if ($this->outputStream) {
            fwrite($this->outputStream, $data);
            return '';
        }
        return $data;
    }

    /**
     * Format an ADORecordSet to target output format.
     * Input: ADODB RecordSet with data rows from query or table dump
     * Output: Written to stream (if set) or accumulated as string
     *
     * @param mixed $recordset ADORecordSet with data rows
     * @param array $metadata Optional metadata (table name, columns, types, etc.)
     * @return string The formatted output (empty if using stream)
     */
    abstract public function format($recordset, $metadata = []);
}
