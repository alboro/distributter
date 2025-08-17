<?php

declare(strict_types=1);

namespace Sc\Integration\Tg\Retriever;

use Sc\Config\CommonChannelConfigDto;

readonly class TgRetrieverConfig
{
    public function __construct(
        public string $apiId,
        public string $apiHash,
        public string $channel,
        public ?string $inviteLink,
        public string $sessionFile,
        public int $timeoutSec,
        public int $maxRetries,
        public int $retryDelay,
        private CommonChannelConfigDto $dto,
    ) {}

    public static function fromEnvironment(): self
    {
        if (!isset($_ENV['TG_API_ID'], $_ENV['TG_API_HASH'], $_ENV['TG_RETRIEVER_CHANNEL_ID'])) {
            throw new \RuntimeException('Required environment variables are not set: TG_API_ID, TG_API_HASH, TG_RETRIEVER_CHANNEL_ID');
        }

        return new self(
            apiId: $_ENV['TG_API_ID'],
            apiHash: $_ENV['TG_API_HASH'],
            channel: $_ENV['TG_RETRIEVER_CHANNEL_ID'],
            inviteLink: $_ENV['TG_RETRIEVER_INVITE_LINK'] ?? null,
            sessionFile: dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . ($_ENV['TG_SESSION_FILE'] ?? 'var/session.madeline'),
            timeoutSec: (int)($_ENV['TG_RETRIEVAL_TIMEOUT_SEC'] ?? 30),
            maxRetries: (int)($_ENV['TG_RETRIEVAL_MAX_RETRIES'] ?? 3),
            retryDelay: (int)($_ENV['TG_RETRIEVAL_RETRY_DELAY'] ?? 2),
            dto: new CommonChannelConfigDto(
                itemCount: (int)($_ENV['ITEM_COUNT'] ?? 5),
                ignoreTag: $_ENV['TAG_OF_IGNORE'] ?? '#local',
            )
        );
    }

    public function itemCount(): int
    {
        return $this->dto->itemCount;
    }

    public function ignoreTag(): string
    {
        return $this->dto->ignoreTag;
    }
}
