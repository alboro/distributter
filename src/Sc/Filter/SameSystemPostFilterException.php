<?php

declare(strict_types=1);

namespace Sc\Filter;

class SameSystemPostFilterException extends PostFilterException
{
    public function __construct(string $message = 'No need to post to source system', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}