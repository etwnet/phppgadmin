<?php

namespace PhpPgAdmin\Database\Import;

class CompressionReader
{
    /**
     * Returns support flags for compression formats based on available PHP extensions.
     *
     * @return array{gzip:bool,zip:bool,bzip2:bool}
     */
    public static function capabilities(): array
    {
        static $caps = null;
        if ($caps !== null) {
            return $caps;
        }

        $caps = [
            'gzip' => function_exists('gzopen'),
            'zip' => class_exists('ZipArchive'),
            'bzip2' => function_exists('bzopen'),
        ];

        return $caps;
    }

    public static function isSupported(string $type): bool
    {
        if ($type === 'plain') {
            return true;
        }

        $caps = self::capabilities();
        return isset($caps[$type]) ? (bool) $caps[$type] : false;
    }

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
