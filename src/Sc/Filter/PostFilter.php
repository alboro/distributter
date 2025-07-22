<?php

declare(strict_types=1);

namespace Sc\Filter;

use Psr\Log\LoggerInterface;
use Sc\Dto\TransferPostDto;

readonly class PostFilter
{
    /**
     * @param TransferPostDto $transferPost
     * @return void
     */
    public function validate(TransferPostDto $transferPost): void
    {
        if ($transferPost->fromSystemName === $transferPost->otherSystemName) {
            throw new SameSystemPostFilterException();
        }
        $systems = $transferPost->post->ids->getSystems();
        if (in_array($transferPost->otherSystemName, $systems, true)) {
            throw new PostFilterException('Already posted before');
        }
    }
}
