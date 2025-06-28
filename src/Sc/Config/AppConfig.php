<?php

declare(strict_types=1);

namespace Sc\Config;

use Override;

readonly class AppConfig
{
    public function __construct(
        public bool $useTgApi = true,
        public bool $enableNotification = false,
        public string $vkToken = '',
        public string $vkGroupId = '',
        public string $tgBotToken = '',
        public string $tgChannelId = '',
        public ?string $tgProxyDSN = null,
        public string $ignoreTag = '#vk',
        public int $itemCount = 5,
        public int $requestTimeoutSec = 30,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            useTgApi: !($_ENV['DRY_RUN'] ?? false),
            enableNotification: false,
            vkToken: $_ENV['VK_TOKEN'] ?? throw new \InvalidArgumentException('VK_TOKEN is required'),
            vkGroupId: $_ENV['VK_GROUP_ID'] ?? throw new \InvalidArgumentException('VK_GROUP_ID is required'),
            tgBotToken: $_ENV['TG_BOT_TOKEN'] ?? throw new \InvalidArgumentException('TG_BOT_TOKEN is required'),
            tgChannelId: $_ENV['TG_CHANNEL_ID'] ?? throw new \InvalidArgumentException('TG_CHANNEL_ID is required'),
            tgProxyDSN: $_ENV['TG_PROXY_DSN'] ?? null,
            ignoreTag: $_ENV['TAG_OF_IGNORE'] ?? '#vk',
            itemCount: (int)($_ENV['ITEM_COUNT'] ?? 5),
            requestTimeoutSec: (int)($_ENV['REQUEST_TIMEOUT_SEC'] ?? 30),
        );
    }

    public function isDryRun(): bool
    {
        return !$this->useTgApi;
    }
}
