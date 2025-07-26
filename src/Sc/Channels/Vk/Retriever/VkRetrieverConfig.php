<?php

declare(strict_types=1);

namespace Sc\Channels\Vk\Retriever;

use Sc\Config\AppConfig;

readonly class VkRetrieverConfig
{
    private function __construct(
        public string $token,
        public string $groupId,
        public int $itemCount,
        public string $ignoreTag,
        public array $excludePostIds = [],
    ) {}

    public static function fromEnvironment(): ?self
    {
        $token = $_ENV['VK_RETRIEVER_TOKEN'] ?? null;
        $groupId = $_ENV['VK_RETRIEVER_GROUP_ID'] ?? null;
        $itemCount = isset($_ENV['ITEM_COUNT']) ? (int) $_ENV['ITEM_COUNT'] : AppConfig::ITEM_COUNT;
        $tagOfIgnore = $_ENV['TAG_OF_IGNORE'] ?? AppConfig::TAG_OF_IGNORE;
        $excludePostIds = isset($_ENV['EXCLUDE_VK_POST_IDS']) ? explode(',', $_ENV['EXCLUDE_VK_POST_IDS']) : [
            5200, 5279, 5288, 5356, 5397, 5483, 5528, 6302, 6617, 6651, 6878, 7015,
            880, 885, 930, 946, 958, 966, 1010, 1011, 1025, 1078, 1089, 1113, 1138,
            1139, 1141, 1330, 1345, 1498, 1579, 1664, 1720, 1780, 1781, 1793, 1817,
            1837, 1852, 2949, 3414, 3415, 3425, 3443, 3452, 3465, 3472, 3479, 3584,
            3591, 3629, 3760, 3778, 3869, 3878, 3906, 3943, 3960, 3963, 3979, 3983,
            3996, 4003, 4005, 4021, 4042, 4078, 4133, 4200, 4209, 4213, 4215, 4219,
            4238, 4247, 4272, 4284, 4294, 4310, 4313, 4318, 4320, 4368, 4391, 4412,
            4430, 4433, 4436, 4438, 4449, 4450, 4465, 4468, 4474, 4488, 4495, 4502,
            4520, 4542, 4545, 4550, 4552, 4567, 4580, 4590, 4593, 4596, 4599, 4602,
            4606, 4622, 4735, 4744, 5140, 5117, 5114, 5069, 4981, 4972, 4956, 4940,
            4938, 4909, 4889, 4863, 4858, 4857, 4854, 4853, 4852, 4851, 4844, 4818,
            4808, 4795, 4794, 4773, 4772, 4771, 4750, 5165, 5182, 5209, 6823, 6908,
            1502
        ];

        // Если какой-то из параметров не указан, возвращаем null
        if (null === $token || null === $groupId || null === $itemCount || null === $tagOfIgnore){
            return null;
        }

        return new self($token, $groupId, $itemCount, $tagOfIgnore, $excludePostIds);
    }
}
