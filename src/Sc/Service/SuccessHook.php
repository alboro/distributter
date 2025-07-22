<?php

namespace Sc\Service;

use Psr\Log\LoggerInterface;
use Sc\Dto\TransferPostDto;

final readonly class SuccessHook
{
    public function __construct(
        private LoggerInterface $logger,
        private Repository $storage,
    )
    {
    }

    public function handleSuccessfulSend(
        TransferPostDto $transferPost,
        string          $formattedText,
        int             $partNumber = 1,
        int             $totalParts = 1
    ): void {
        if ($transferPost->transferredPostIdCollection()->count() === 0) {
            $this->logger->error('No transferred post ID found', [
                'postId' => (string) $transferPost->post->ids,
                'text' => $formattedText
            ]);
            return;
        }
        $this->storage->addCollection(
            $transferPost->searchCriteriaPostId(),
            $transferPost->transferredPostIdCollection(),
        );

        $context = [
            'channels' => sprintf('from %s to %s', $transferPost->fromSystemName, $transferPost->otherSystemName),
            'fromId' => $transferPost->searchCriteriaPostId()->toString(),
            'toId' => $transferPost->transferredPostIdCollection()->last()?->toString(),
            'text' => $formattedText
        ];

        if ($transferPost->post->hasPhoto()) {
            $context['photo'] = $transferPost->post->photos[0];
        }

        if ($totalParts > 1) {
            $context['parts'] = "$partNumber/$totalParts";
        }
        $this->logger->info(
            $transferPost->post->hasPhoto() ? 'Send new photo' : 'Send new post',
            $context
        );
    }
}