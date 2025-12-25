<?php

namespace PhpPgAdmin\Database\Export;

/**
 * CSV Format Formatter
 * Converts table data to RFC 4180 compliant CSV
 */
class CsvFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/csv; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'csv';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as CSV
     * @param mixed $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     * @return string
     */
    public function format($recordset, $metadata = [])
    {
        $output = '';

        if (!$recordset || $recordset->EOF) {
            return '';
        }

        // Get column names from recordset
        $columns = [];
        for ($i = 0; $i < count($recordset->fields); $i++) {
            $finfo = $recordset->fetchField($i);
            $columns[] = $finfo->name ?? "Column $i";
        }

        // Write header row
        $output .= $this->write($this->escapeCsvLine($columns) . "\r\n");

        // Write data rows
        while (!$recordset->EOF) {
            $row = [];
            foreach ($recordset->fields as $value) {
                $row[] = $value;
            }
            $output .= $this->write($this->escapeCsvLine($row) . "\r\n");
            $recordset->moveNext();
        }

        return $output;
    }

    /**
     * Escape and quote CSV fields
     */
    private function escapeCsvLine(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $field) {
            $escaped[] = $this->quoteCsvField((string) $field);
        }
        return implode(',', $escaped);
    }

    /**
     * Quote a single CSV field if necessary
     */
    private function quoteCsvField(string $field): string
    {
        // Quote if contains comma, double-quote, or newline
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            // Escape double quotes by doubling them
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }
}
