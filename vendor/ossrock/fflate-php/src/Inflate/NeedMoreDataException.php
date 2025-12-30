<?php

namespace Ossrock\FflatePhp\Inflate;

/**
 * Internal control-flow exception used by streaming inflate when more input is required.
 */
class NeedMoreDataException extends \RuntimeException
{
}
