<?php

declare(strict_types=1);

namespace Sc\Filter;

use Psr\Log\LoggerInterface;
use Sc\Storage;

readonly class PostFilter
{
    private const array EXCLUDE_VK_POST_IDS = [
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

    public function __construct(
        private LoggerInterface $logger,
        private Storage $storage,
        private string $vkGroupId,
        private string $ignoreTag
    ) {}

    /**
     * @throws PostFilterException если пост должен быть пропущен
     */
    public function validatePost(array $vkItem): void
    {
        $postId = (int)$vkItem['id'];

        match (true) {
            (int)$vkItem['from_id'] !== (int)$this->vkGroupId =>
                $this->skipPost($postId, 'post by alien'),

            in_array($postId, self::EXCLUDE_VK_POST_IDS, true) =>
                $this->skipPost($postId, 'exclude Vk Post Ids'),

            $vkItem['marked_as_ads'] =>
                $this->skipPost($postId, 'marked as ads'),

            $this->hasIgnoreTag($vkItem['text']) =>
                $this->skipPost($postId, 'tagged with ignore tag'),

            $this->storage->hasId($postId) =>
                $this->skipPost($postId, 'already posted by id'),

            isset($vkItem['copy_history']) =>
                throw new PostFilterException('Reposts are not supported yet'),

            default => null
        };
    }

    public function shouldProcessRepost(array $vkItem): bool
    {
        return !isset($vkItem['copy_history']);
    }

    public function getExcludeIds(): array
    {
        return self::EXCLUDE_VK_POST_IDS;
    }

    private function hasIgnoreTag(string $text): bool
    {
        return (bool)preg_match(
            '/(?:^|\s)' . preg_quote($this->ignoreTag, '/') . '(?:\s|$)/i',
            $text
        );
    }

    private function skipPost(int $postId, string $reason): never
    {
        $this->logger->debug('Skip post', ['reason' => $reason, 'id' => $postId]);
        throw new PostFilterException($reason);
    }
}

class PostFilterException extends \RuntimeException
{
}
