<?php

declare(strict_types=1);

namespace Sc\Channels\Vk\Retriever;

use Sc\Config\AppConfig;
use Sc\Config\CommonChannelConfigDto;

readonly class VkRetrieverConfig
{
    private function __construct(
        public string $token,
        public string $groupId,
        public CommonChannelConfigDto $dto,
        public array $excludePostIds = [],
    ) {}

    public static function fromEnvironment(): ?self
    {
        $token = $_ENV['VK_RETRIEVER_TOKEN'] ?? null;
        $groupId = $_ENV['VK_RETRIEVER_GROUP_ID'] ?? null;
        $itemCount = isset($_ENV['ITEM_COUNT']) ? (int) $_ENV['ITEM_COUNT'] : AppConfig::ITEM_COUNT;
        $tagOfIgnore = $_ENV['TAG_OF_IGNORE'] ?? AppConfig::TAG_OF_IGNORE;
        $excludePostIds = isset($_ENV['EXCLUDE_VK_POST_IDS']) ? explode(',', $_ENV['EXCLUDE_VK_POST_IDS']) : [];

        // Если какой-то из параметров не указан, возвращаем null
        if (null === $token || null === $groupId || null === $itemCount || null === $tagOfIgnore){
            return null;
        }

        return new self($token, $groupId, new CommonChannelConfigDto($itemCount, $tagOfIgnore), $excludePostIds);
    }
}
