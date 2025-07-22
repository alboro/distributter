<?php

declare(strict_types=1);

namespace Sc\Config;

readonly class AppConfig
{
    public const TAG_OF_IGNORE = '#local';
    public const ITEM_COUNT = 5;

    public function __construct(
        public bool    $mockSenders,
        public bool    $enableNotification,
        public ?string  $ignoreTag,
        public int     $itemCount,
        public int     $requestTimeoutSec,
        public string  $storageFilePath,
        public string  $logFilePath,
        // vk
        public ?VkSenderConfig $vkSenderConfig = null,
        public ?VkRetrieverConfig $vkRetrieverConfig = null,
        // tg
        public string  $tgSenderBotToken = '',
        public string  $tgSenderChannelId = '',
        public string  $tgRetrieverApiId = '',
        public string  $tgRetrieverApiHash = '',
        public string  $tgRetrieverChannel = '',
        public string  $tgRetrieverSessionFile = 'session.madeline',
        // fb
        public string  $fbPageAccessToken = '',
        public string  $fbPageId = '',
        public bool    $fbEnableRetriever = false,
        public bool    $fbEnableSender = false,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            mockSenders: !!($_ENV['DRY_RUN'] ?? false),
            enableNotification: false,
            ignoreTag: $_ENV['TAG_OF_IGNORE'] ?? self::TAG_OF_IGNORE,
            itemCount: (int)($_ENV['ITEM_COUNT'] ?? self::ITEM_COUNT),
            requestTimeoutSec: (int)($_ENV['REQUEST_TIMEOUT_SEC'] ?? 30),
            storageFilePath: $_ENV['STORAGE_FILE_PATH'] ?? __DIR__ . '/../../../storage.v3.json',
            logFilePath: $_ENV['LOG_FILE_PATH'] ?? __DIR__ . '/../../../log.log',

            vkSenderConfig: VkSenderConfig::fromEnvironment(),
            vkRetrieverConfig: VkRetrieverConfig::fromEnvironment(),

            tgSenderBotToken: $_ENV['TG_SENDER_BOT_TOKEN'] ?? throw new \InvalidArgumentException('TG_SENDER_BOT_TOKEN is required'),
            tgSenderChannelId: $_ENV['TG_SENDER_CHANNEL_ID'] ?? throw new \InvalidArgumentException('TG_SENDER_CHANNEL_ID is required'),
            tgRetrieverApiId: $_ENV['TG_API_ID'] ?? '',
            tgRetrieverApiHash: $_ENV['TG_API_HASH'] ?? '',
            tgRetrieverChannel: $_ENV['TG_RETRIEVER_CHANNEL_ID'] ?? '',
            tgRetrieverSessionFile: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ($_ENV['TG_SESSION_FILE'] ?? 'session.madeline'),

            fbPageAccessToken: $_ENV['FB_PAGE_ACCESS_TOKEN'] ?? '',
            fbPageId: $_ENV['FB_PAGE_ID'] ?? '',
            fbEnableRetriever: filter_var($_ENV['FB_ENABLE_RETRIEVER'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            fbEnableSender: filter_var($_ENV['FB_ENABLE_SENDER'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        );
    }
}
