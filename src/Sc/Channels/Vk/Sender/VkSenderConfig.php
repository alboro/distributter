<?php

declare(strict_types=1);

namespace Sc\Channels\Vk\Sender;

readonly class VkSenderConfig
{
    private function __construct(
        public string $token,
        public string $groupId,
        public int $requestTimeoutSec,
    ) {}

    public static function fromEnvironment(): ?self
    {
        $token = $_ENV['VK_SENDER_TOKEN'] ?? '';
        $groupId = $_ENV['VK_SENDER_GROUP_ID'] ?? '';

        // Если какой-то из параметров не указан, возвращаем null
        if (empty($token) || empty($groupId)) {
            return null;
        }

        return new self($token, $groupId, (int)($_ENV['REQUEST_TIMEOUT_SEC'] ?? 30));
    }
}
