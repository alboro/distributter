<?php

namespace Sc\Config;

final readonly class CommonChannelConfigDto
{
    public function __construct(
        public int     $itemCount,
        public ?string $ignoreTag,
        public int     $requestTimeoutSec = 30,
    ) {}
}