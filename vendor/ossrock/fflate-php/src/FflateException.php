<?php

namespace Ossrock\FflatePhp;

use Exception;

/**
 * Exception thrown by fflate-php operations
 */
class FflateException extends Exception
{
    // Error codes matching fflate
    const UNEXPECTED_EOF = 0;
    const INVALID_BLOCK_TYPE = 1;
    const INVALID_LENGTH_LITERAL = 2;
    const INVALID_DISTANCE = 3;
    const STREAM_FINISHED = 4;
    const NO_STREAM_HANDLER = 5;
    const INVALID_HEADER = 6;
    const NO_CALLBACK = 7;
    const INVALID_UTF8 = 8;
    const EXTRA_FIELD_TOO_LONG = 9;
    const INVALID_DATE = 10;
    const FILENAME_TOO_LONG = 11;
    const STREAM_FINISHING = 12;
    const INVALID_ZIP_DATA = 13;
    const UNKNOWN_COMPRESSION_METHOD = 14;

    /**
     * Error messages
     * @var string[]
     */
    private static $messages = [
        self::UNEXPECTED_EOF => 'unexpected EOF',
        self::INVALID_BLOCK_TYPE => 'invalid block type',
        self::INVALID_LENGTH_LITERAL => 'invalid length/literal',
        self::INVALID_DISTANCE => 'invalid distance',
        self::STREAM_FINISHED => 'stream finished',
        self::NO_STREAM_HANDLER => 'no stream handler',
        self::INVALID_HEADER => 'invalid header',
        self::NO_CALLBACK => 'no callback',
        self::INVALID_UTF8 => 'invalid UTF-8 data',
        self::EXTRA_FIELD_TOO_LONG => 'extra field too long',
        self::INVALID_DATE => 'date not in range 1980-2099',
        self::FILENAME_TOO_LONG => 'filename too long',
        self::STREAM_FINISHING => 'stream finishing',
        self::INVALID_ZIP_DATA => 'invalid ZIP data',
        self::UNKNOWN_COMPRESSION_METHOD => 'unknown compression method',
    ];

    /**
     * Create exception from error code
     *
     * @param int $code Error code
     * @param string|null $message Optional custom message
     * @return self
     */
    public static function create($code, $message = null)
    {
        if ($message === null && isset(self::$messages[$code])) {
            $message = self::$messages[$code];
        }
        return new self($message, $code);
    }
}
