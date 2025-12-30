<?php

namespace Ossrock\FflatePhp\Filter;

use Ossrock\FflatePhp\Stream\ZlibStreamEncoder;

class ZlibEncodeFilter extends \php_user_filter
{
    /** @var ZlibStreamEncoder */
    private $enc;

    public function onCreate(): bool
    {
        $level = 6;
        if (is_array($this->params) && isset($this->params['level'])) {
            $level = (int) $this->params['level'];
        }
        $this->enc = new ZlibStreamEncoder($level);
        return true;
    }

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;

            $encoded = $this->enc->append($bucket->data);
            if ($encoded !== '') {
                $ob = stream_bucket_new($this->stream, $encoded);
                stream_bucket_append($out, $ob);
            }
        }

        if ($closing) {
            $tail = $this->enc->finish();
            if ($tail !== '') {
                $ob = stream_bucket_new($this->stream, $tail);
                stream_bucket_append($out, $ob);
            }
        }

        return PSFS_PASS_ON;
    }
}
