<?php

namespace PhpPgAdmin\Database\Export;

/**
 * XHTML Format Formatter
 * Converts table data to XHTML 1.0 Transitional table format
 */
class HtmlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'html';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as XHTML
     * @param mixed $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     * @return string
     */
    public function format($recordset, $metadata = [])
    {
        $output = '';
        $output .= $this->write('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $output .= $this->write('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n");
        $output .= $this->write('<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">' . "\n");
        $output .= $this->write("<head>\n");
        $output .= $this->write('<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\n");
        $output .= $this->write('<title>Database Export</title>' . "\n");
        $output .= $this->write('<style type="text/css">' . "\n");
        $output .= $this->write("table { border-collapse: collapse; border: 1px solid #999; }\n");
        $output .= $this->write("th { background-color: #f0f0f0; border: 1px solid #999; padding: 5px; text-align: left; font-weight: bold; }\n");
        $output .= $this->write("td { border: 1px solid #999; padding: 5px; }\n");
        $output .= $this->write("tr:nth-child(even) { background-color: #f9f9f9; }\n");
        $output .= $this->write("</style>\n");
        $output .= $this->write("</head>\n");
        $output .= $this->write("<body>\n");
        $output .= $this->write("<table>\n");

        if (!$recordset || $recordset->EOF) {
            $output .= $this->write("</table>\n</body>\n</html>\n");
            return $output;
        }

        // Get column names and write header
        $columns = [];
        for ($i = 0; $i < count($recordset->fields); $i++) {
            $finfo = $recordset->fetchField($i);
            $columns[] = $finfo->name ?? "Column $i";
        }

        $output .= $this->write("<thead>\n<tr>\n");
        foreach ($columns as $column) {
            $output .= $this->write('<th>' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>' . "\n");
        }
        $output .= $this->write("</tr>\n</thead>\n");

        // Write data rows
        $output .= $this->write("<tbody>\n");
        while (!$recordset->EOF) {
            $output .= $this->write("<tr>\n");
            foreach ($recordset->fields as $value) {
                $output .= $this->write('<td>' . htmlspecialchars($value ?? 'NULL', ENT_QUOTES, 'UTF-8') . '</td>' . "\n");
            }
            $output .= $this->write("</tr>\n");
            $recordset->moveNext();
        }
        $output .= $this->write("</tbody>\n");

        $output .= $this->write("</table>\n");
        $output .= $this->write("</body>\n");
        $output .= $this->write("</html>\n");

        return $output;
    }
}
