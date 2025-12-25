<?php

namespace PhpPgAdmin\Database\Export;

/**
 * COPY Format Formatter
 * Outputs PostgreSQL COPY format as-is
 */
class CopyFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'sql';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as COPY FROM stdin format
     * @param mixed $recordset ADORecordSet
     * @param array $metadata with key: table
     * @return string
     */
    public function format($recordset, $metadata = [])
    {
        // COPY format is same as SQL COPY option, delegate to SqlFormatter logic
        $table_name = $metadata['table'] ?? 'data';
        $output = '';

        if (!$recordset || $recordset->EOF) {
            return '';
        }

        // Get column information
        $columns = [];
        for ($i = 0; $i < count($recordset->fields); $i++) {
            $finfo = $recordset->fetchField($i);
            $columns[] = $finfo->name ?? "Column $i";
        }

        $line = "COPY \"{$table_name}\" (" . implode(', ', array_map(function ($col) {
            return '"' . $col . '"';
        }, $columns)) . ") FROM stdin;\n";
        $output .= $this->write($line);

        while (!$recordset->EOF) {
            $first = true;
            $line = '';
            foreach ($recordset->fields as $v) {
                $v = $this->escapeBytea($v);
                $v = preg_replace('/\\\\([0-7]{3})/', '\\\\\1', $v);
                if ($first) {
                    $line .= (is_null($v)) ? '\\N' : $v;
                    $first = false;
                } else {
                    $line .= "\t" . ((is_null($v)) ? '\\N' : $v);
                }
            }
            $line .= "\n";
            $output .= $this->write($line);
            $recordset->moveNext();
        }
        $output .= $this->write("\\.\n");

        return $output;
    }

    /**
     * Escape value for COPY format
     */
    private function escapeBytea($value)
    {
        if ($value === null) {
            return null;
        }
        return addcslashes($value, "\0\\\n\r\t");
    }
}
