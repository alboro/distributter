<?php

declare(strict_types=1);

namespace Sc\Channels\Tg\Sender;

readonly class TgSenderConfig
{
    public function __construct(
        public string $botToken,
        public string $channelId,
        public bool $enableNotification = false,
    ) {}

    public static function fromEnvironment(): ?self
    {
        if (!isset($_ENV['TG_SENDER_BOT_TOKEN']) || !isset($_ENV['TG_SENDER_CHANNEL_ID'])) {
            return null;
        }
        return new self(
            botToken: (string) $_ENV['TG_SENDER_BOT_TOKEN'],
            channelId: (string) $_ENV['TG_SENDER_CHANNEL_ID'],
        );
    }
}
