<?php

namespace PhpPgAdmin\Database\Export;

/**
 * XML Format Formatter
 * Converts table data to XML with structure and data
 */
class XmlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/xml; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'xml';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as XML
     * @param mixed $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     * @return string
     */
    public function format($recordset, $metadata = [])
    {
        $output = '';
        $output .= $this->write('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $output .= $this->write("<data>\n");

        if (!$recordset || $recordset->EOF) {
            $output .= $this->write("</data>\n");
            return $output;
        }

        // Get column information from recordset fields
        $columns = [];
        $fieldIndex = 0;
        foreach ($recordset->fields as $fieldName => $fieldValue) {
            $finfo = $recordset->fetchField($fieldIndex);
            $type = $finfo->type ?? 'unknown';

            $columns[$fieldIndex] = [
                'name' => $finfo->name ?? $fieldName,
                'type' => $type
            ];
            $fieldIndex++;
        }

        // Write header with column information
        $output .= $this->write("<header>\n");
        foreach ($columns as $col) {
            $name = $this->xmlEscape($col['name']);
            $type = $this->xmlEscape($col['type']);
            $output .= $this->write("\t<column name=\"{$name}\" type=\"{$type}\" />\n");
        }
        $output .= $this->write("</header>\n");

        // Write records section
        $output .= $this->write("<records>\n");
        while (!$recordset->EOF) {
            $output .= $this->write("\t<row>\n");
            $i = 0;
            foreach ($recordset->fields as $fieldValue) {
                if (isset($columns[$i])) {
                    $col_name = $this->xmlEscape($columns[$i]['name']);
                    $value = $fieldValue;
                    if (!is_null($value)) {
                        $value = $this->xmlEscape($value);
                    }
                    $output .= $this->write("\t\t<column name=\"{$col_name}\"" . (is_null($value) ? ' null="null"' : '') . ">{$value}</column>\n");
                }
                $i++;
            }
            $output .= $this->write("\t</row>\n");
            $recordset->moveNext();
        }
        $output .= $this->write("</records>\n");

        $output .= $this->write("</data>\n");

        return $output;
    }

    /**
     * XML-escape string
     */
    private function xmlEscape($str)
    {
        return htmlspecialchars($str, ENT_XML1, 'UTF-8');
    }
}
