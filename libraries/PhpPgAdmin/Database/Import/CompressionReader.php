<?php

namespace PhpPgAdmin\Database\Import;

class CompressionReader
{
    public static function detect(string $filePath): string
    {
        $h = fopen($filePath, 'rb');
        $bytes = fread($h, 4);
        fclose($h);
        $ord = array_map('ord', str_split($bytes));
        if (isset($ord[0]) && $ord[0] === 0x1f && isset($ord[1]) && $ord[1] === 0x8b) {
            return 'gzip';
        }
        if (isset($ord[0]) && $ord[0] === 0x42 && isset($ord[1]) && $ord[1] === 0x5a) {
            return 'bzip2';
        }
        if (isset($ord[0]) && $ord[0] === 0x50 && isset($ord[1]) && $ord[1] === 0x4b) {
            return 'zip';
        }
        return 'plain';
    }
}
