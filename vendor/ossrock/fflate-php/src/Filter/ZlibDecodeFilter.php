<?php

namespace Ossrock\FflatePhp\Filter;

use Ossrock\FflatePhp\Stream\ZlibStreamDecoder;

class ZlibDecodeFilter extends \php_user_filter
{
    /** @var ZlibStreamDecoder */
    private $dec;

    public function onCreate(): bool
    {
        $this->dec = new ZlibStreamDecoder();
        return true;
    }

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $decoded = $this->dec->append($bucket->data, false);
            if ($decoded !== '') {
                $ob = stream_bucket_new($this->stream, $decoded);
                stream_bucket_append($out, $ob);
            }
        }

        if ($closing) {
            $tail = $this->dec->append('', true);
            if ($tail !== '') {
                $ob = stream_bucket_new($this->stream, $tail);
                stream_bucket_append($out, $ob);
            }
        }

        return PSFS_PASS_ON;
    }
}
