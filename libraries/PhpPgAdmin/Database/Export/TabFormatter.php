<?php

namespace PhpPgAdmin\Database\Export;

/**
 * Tab-Delimited Format Formatter
 * Converts table data to tab-separated values with quoted fields
 */
class TabFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'txt';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as tab-delimited
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
        $output .= $this->write($this->escapeTabLine($columns) . "\r\n");

        // Write data rows
        while (!$recordset->EOF) {
            $row = [];
            foreach ($recordset->fields as $value) {
                $row[] = $value;
            }
            $output .= $this->write($this->escapeTabLine($row) . "\r\n");
            $recordset->moveNext();
        }

        return $output;
    }

    /**
     * Escape and quote tab-delimited line fields
     */
    private function escapeTabLine(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $field) {
            $escaped[] = $this->quoteTabField((string) $field);
        }
        return implode("\t", $escaped);
    }

    /**
     * Quote a field if it contains tabs or newlines
     */
    private function quoteTabField(string $field): string
    {
        if (strpos($field, "\t") !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }
}
