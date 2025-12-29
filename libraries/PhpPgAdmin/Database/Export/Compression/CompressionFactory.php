<?php
namespace PhpPgAdmin\Database\Export\Compression;

class CompressionFactory
{
    /**
     * Create a compression strategy for given type.
     * Supported types: 'download'|'plain'|'gzipped'|'gzip'|'bzip2'|'bz2'|'zip'
     * @param string $type
     * @return CompressionStrategy|null
     */
    public static function create(string $type): ?CompressionStrategy
    {
        $t = strtolower(trim($type));
        switch ($t) {
            case 'download':
            case 'plain':
                return new PlainStrategy();
            case 'gzipped':
            case 'gzip':
                return new GzipStrategy();
            case 'bzip2':
            case 'bz2':
                return new Bzip2Strategy();
            case 'zip':
                return new ZipStrategy();
            default:
                return null;
        }
    }
}
