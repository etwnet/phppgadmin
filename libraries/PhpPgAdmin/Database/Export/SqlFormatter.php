<?php

namespace PhpPgAdmin\Database\Export;

/**
 * SQL Format Formatter
 * Outputs PostgreSQL SQL statements as-is or slightly processed
 */
class SqlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'sql';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as SQL INSERT statements
     * @param mixed $recordset ADORecordSet
     * @param array $metadata with keys: table, columns, insert_format
     * @return string
     */
    public function format($recordset, $metadata = [])
    {
        $table_name = $metadata['table'] ?? 'data';
        $insert_format = $metadata['insert_format'] ?? 'multi'; // multi, single, or copy
        $output = '';

        if (!$recordset || $recordset->EOF) {
            return '';
        }

        // Get column information
        $columns = [];
        for ($i = 0; $i < count($recordset->fields); $i++) {
            $finfo = $recordset->fetchField($i);
            $columns[] = $finfo->name ?? "column_$i";
        }

        if ($insert_format === 'copy') {
            // COPY format
            $line = "COPY \"{$table_name}\" (" . implode(', ', array_map(function ($col) {
                return '"' . $col . '"';
            }, $columns)) . ") FROM stdin;\n";
            $output .= $this->write($line);

            while (!$recordset->EOF) {
                $first = true;
                $line = '';
                foreach ($recordset->fields as $v) {
                    $v = $this->escapeBytea($v);
                    if (!is_null($v)) {
                        $v = preg_replace('/\\\\([0-7]{3})/', '\\\\\1', $v);
                    }
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
        } else {
            // Standard INSERT statements (multi or single)
            $rows_for_batch = ($insert_format === 'multi') ? [] : [];

            while (!$recordset->EOF) {
                $values = [];
                foreach ($recordset->fields as $v) {
                    if (is_null($v)) {
                        $values[] = 'NULL';
                    } else {
                        $v = addCSlashes($v, "\0..\37\177..\377");
                        $v = preg_replace('/\\\\([0-7]{3})/', '\\\1', $v);
                        $v = str_replace("'", "''", $v);
                        $values[] = "'" . $v . "'";
                    }
                }

                if ($insert_format === 'single') {
                    $line = "INSERT INTO \"{$table_name}\" (" . implode(', ', array_map(function ($col) {
                        return '"' . $col . '"';
                    }, $columns)) . ") VALUES (" . implode(', ', $values) . ");\n";
                    $output .= $this->write($line);
                } else {
                    // multi: collect rows for batch INSERT
                    $rows_for_batch[] = "(" . implode(', ', $values) . ")";
                }

                $recordset->moveNext();
            }

            // Output multi-row INSERT statements
            if ($insert_format === 'multi' && !empty($rows_for_batch)) {
                $line = "INSERT INTO \"{$table_name}\" (" . implode(', ', array_map(function ($col) {
                    return '"' . $col . '"';
                }, $columns)) . ") VALUES\n";
                $output .= $this->write($line);
                $output .= $this->write(implode(",\n", $rows_for_batch) . ";\n");
            }
        }

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
        // COPY escaping: backslash and non-printable chars
        return addcslashes($value, "\0\\\n\r\t");
    }
}
