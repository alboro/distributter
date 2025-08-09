<?php

declare(strict_types=1);

namespace Sc\Integration\Vk\Retriever;

use Sc\Config\AppConfig;
use Sc\Config\CommonChannelConfigDto;

readonly class VkRetrieverConfig
{
    private function __construct(
        public string $token,
        public string $groupId,
        private CommonChannelConfigDto $dto,
        public array $excludePostIds = [],
    ) {}

    public function itemCount(): int
    {
        return $this->dto->itemCount;
    }

    public function ignoreTag(): string
    {
        return $this->dto->ignoreTag;
    }

    public static function fromEnvironment(): ?self
    {
        $token = $_ENV['VK_RETRIEVER_TOKEN'] ?? null;
        $groupId = $_ENV['VK_RETRIEVER_GROUP_ID'] ?? null;
        $itemCount = isset($_ENV['VK_RETRIEVER_ITEM_COUNT']) ? (int) $_ENV['VK_RETRIEVER_ITEM_COUNT'] : 5;
        $tagOfIgnore = $_ENV['TAG_OF_IGNORE'] ?? AppConfig::TAG_OF_IGNORE;
        $excludePostIds = isset($_ENV['EXCLUDE_VK_POST_IDS']) ? explode(',', $_ENV['EXCLUDE_VK_POST_IDS']) : [];

        // Если какой-то из параметров не указан, возвращаем null
        if (null === $token || null === $groupId || null === $itemCount || null === $tagOfIgnore){
            return null;
        }

        return new self($token, $groupId, new CommonChannelConfigDto($itemCount, $tagOfIgnore), $excludePostIds);
    }
}
