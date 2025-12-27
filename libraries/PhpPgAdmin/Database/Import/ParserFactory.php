<?php

namespace PhpPgAdmin\Database\Import;

class ParserFactory
{
    public static function createFromFile(string $filePath): InputParser
    {
        // Very small heuristic: check magic bytes for compressed types or .sql/.csv extension
        $h = fopen($filePath, 'rb');
        $bytes = fread($h, 4);
        fclose($h);

        $ord = array_map('ord', str_split($bytes));
        // gzip magic 1F 8B
        if (isset($ord[0]) && $ord[0] === 0x1f && isset($ord[1]) && $ord[1] === 0x8b) {
            return new GzipSqlParser($filePath);
        }

        // default to SqlParser for now
        return new SqlParser($filePath);
    }
}
