<?php

declare(strict_types=1);

namespace Sc\Channels\Vk;

use Psr\Log\LoggerInterface;
use VK\Client\VKApiClient;

readonly class AuthorService
{
    public function __construct(
        private VKApiClient $vk,
        private string $vkToken,
        private LoggerInterface $logger
    ) {}

    public function getAuthorFromPost(array $vkItem): ?string
    {
        return match (true) {
            isset($vkItem['signer_id']) =>
                $this->getUserName($vkItem['signer_id']),

            empty($vkItem['check_sign']) && isset($vkItem['post_author_data']['author']) =>
                $this->getUserName($vkItem['post_author_data']['author']),

            default => null
        };
    }

    private function getUserName(int $userId): ?string
    {
        try {
            $response = $this->vk->users()->get($this->vkToken, [
                'user_ids' => [$userId],
                'fields' => [],
            ]);

            if (empty($response[0])) {
                return null;
            }

            $user = $response[0];
            $fullName = trim($user['first_name'] . ' ' . $user['last_name']);

            return $fullName === 'DELETED' ? null : $fullName;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get author', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
