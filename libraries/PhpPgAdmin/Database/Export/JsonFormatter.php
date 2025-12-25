<?php

namespace PhpPgAdmin\Database\Export;

/**
 * JSON Format Formatter
 * Converts table data to structured JSON with metadata
 */
class JsonFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'application/json; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'json';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as JSON
     * @param mixed $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     * @return string
     */
    public function format($recordset, $metadata = [])
    {
        $json = [
            'metadata' => [
                'exported_at' => date('Y-m-d H:i:s'),
                'columns' => [],
                'row_count' => 0,
            ],
            'data' => [],
        ];

        if (!$recordset || $recordset->EOF) {
            $output = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return $this->write($output);
        }

        // Get column information
        $columns = [];
        $fieldIndex = 0;
        foreach ($recordset->fields as $fieldName => $fieldValue) {
            $finfo = $recordset->fetchField($fieldIndex);
            $type = $finfo->type ?? 'unknown';

            $col_name = $finfo->name ?? $fieldName;
            $columns[$fieldIndex] = [
                'name' => $col_name,
                'type' => $type
            ];
            $json['metadata']['columns'][] = [
                'name' => $col_name,
                'type' => $type
            ];
            $fieldIndex++;
        }

        // Write rows as objects mapped to column names
        $row_count = 0;
        while (!$recordset->EOF) {
            $row_obj = [];
            $i = 0;
            foreach ($recordset->fields as $fieldValue) {
                if (isset($columns[$i])) {
                    $col_name = $columns[$i]['name'];
                    $row_obj[$col_name] = $fieldValue;
                }
                $i++;
            }
            $json['data'][] = $row_obj;
            $row_count++;
            $recordset->moveNext();
        }

        $json['metadata']['row_count'] = $row_count;

        $output = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        return $this->write($output);
    }
}
