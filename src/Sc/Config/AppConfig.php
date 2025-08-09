<?php

declare(strict_types=1);

namespace Sc\Config;

use Sc\Integration\Tg\Retriever\TgRetrieverConfig;
use Sc\Integration\Tg\Sender\TgSenderConfig;
use Sc\Integration\Vk\Retriever\VkRetrieverConfig;
use Sc\Integration\Vk\Sender\VkSenderConfig;

readonly class AppConfig
{
    public const TAG_OF_IGNORE = '#local';
    public function __construct(
        public bool    $mockSenders,
        public int     $requestTimeoutSec,
        public string  $storageFilePath,
        public string  $logFilePath,
        // vk
        public ?VkSenderConfig $vkSenderConfig = null,
        public ?VkRetrieverConfig $vkRetrieverConfig = null,
        // tg
        public ?TgSenderConfig $tgSenderConfig = null,
        public ?TgRetrieverConfig $tgRetrieverConfig = null,
        // fb
        public string  $fbPageAccessToken = '',
        public string  $fbPageId = '',
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            mockSenders: !!($_ENV['DRY_RUN'] ?? false),
            requestTimeoutSec: (int)($_ENV['REQUEST_TIMEOUT_SEC'] ?? 30),
            storageFilePath: $_ENV['STORAGE_FILE_PATH'] ?? __DIR__ . '/../../../storage.v3.json',
            logFilePath: $_ENV['LOG_FILE_PATH'] ?? __DIR__ . '/../../../log.log',

            vkSenderConfig: VkSenderConfig::fromEnvironment(),
            vkRetrieverConfig: VkRetrieverConfig::fromEnvironment(),

            tgSenderConfig: TgSenderConfig::fromEnvironment(),
            tgRetrieverConfig: TgRetrieverConfig::fromEnvironment(),

            fbPageAccessToken: $_ENV['FB_PAGE_ACCESS_TOKEN'] ?? '',
            fbPageId: $_ENV['FB_PAGE_ID'] ?? '',
        );
    }
}
