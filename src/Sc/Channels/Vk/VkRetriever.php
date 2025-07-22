<?php

declare(strict_types=1);

namespace Sc\Channels\Vk;

use Psr\Log\LoggerInterface;
use Sc\Channels\RetrieverInterface;
use Sc\Config\AppConfig;
use Sc\Config\VkRetrieverConfig;
use Sc\Filter\PostFilterException;
use Sc\Model\{Post, PostId, PostIdCollection};
use Sc\Service\Repository;
use VK\Client\VKApiClient;

/**
 * Extracts and processes posts from VK API
 */
readonly class VkRetriever implements RetrieverInterface
{
    public function __construct(
        private VKApiClient $vk,
        private VkRetrieverConfig $config,
        private LoggerInterface $logger,
        private VkAttachmentParser $attachmentParser,
        private AuthorService $authorService,
        private Repository $storage,
        private string $systemName,
    ) {}

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function channelId(): string
    {
        return $this->config->groupId ?? '';
    }

    /**
     * Gets posts from VK
     *
     * @return Post[]
     */
    public function retrievePosts(): array
    {
        $vkData = $this->fetchVkPosts();
        if (!$this->validateVkResponse($vkData)) {
            return [];
        }

        return $this->processVkItems($vkData['items']);
    }

    private function fetchVkPosts(): array
    {
        return $this->vk->wall()->get($this->config->token, [
            'owner_id' => $this->config->groupId,
            'offset' => 0,
            'count' => $this->config->itemCount,
        ]);
    }

    private function validateVkResponse(array $vkData): bool
    {
        if (!isset($vkData['items'])) {
            $this->logger->error('empty VK response');
            return false;
        }
        return true;
    }

    /**
     * @return Post[]
     */
    private function processVkItems(array $vkItems): array
    {
        $posts = [];

        // Process posts in reverse order (from old to new)
        foreach (array_reverse($vkItems) as $vkItem) {
            try {
                $posts[] = $this->convertVkItemToPost($vkItem);
            } catch (PostFilterException) {
                // Skip filtered posts
                continue;
            } catch (\Throwable $e) {
                $this->logger->error('Error processing VK item', [
                    'post_id' => $vkItem['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        return $posts;
    }

    private function convertVkItemToPost(array $vkItem): Post
    {
        $postId = (string) $vkItem['id'];

        $this->validatePost($vkItem);

        $text = $vkItem['text'];
        $author = $this->authorService->getAuthorFromPost($vkItem); // @todo: make this lazy-loaded when data is actually needed
        sleep(1);

        // Parse attachments
        $videos = $this->attachmentParser->parseVideos($vkItem);
        $links = $this->attachmentParser->parseLinks($vkItem);
        $photos = $this->attachmentParser->parsePhotos($vkItem);
        $poll = $this->attachmentParser->parsePoll($vkItem);

        $postId = new PostId($postId, $this->systemName);
        $collection = $this->storage->find($postId) ?? (new PostIdCollection())->add($postId);

        return new Post(
            ids: $collection,
            text: $text,
            videos: $videos,
            links: $links,
            photos: $photos,
            author: $author,
            poll: $poll,
        );
    }

    /**
     * VK post validation (moved from PostFilter, except storage->hasId)
     *
     * @throws PostFilterException
     */
    private function validatePost(array $vkItem): void
    {
        $postId = (int)$vkItem['id'];

        $expectedGroupId = $this->config->groupId;
        if (!$expectedGroupId || (int)$vkItem['from_id'] !== (int)$expectedGroupId) {
            $this->logger->debug('Skip post', ['reason' => 'post by alien', 'vk_id' => $postId]);
            throw new PostFilterException('Post by alien');
        }

        if (in_array($postId, $this->config->excludePostIds, true)) {
            $this->logger->debug('Skip post', ['reason' => 'exclude Vk Post Ids', 'vk_id' => $postId]);
            throw new PostFilterException('Post in exclude list');
        }

        if ($vkItem['marked_as_ads'] ?? false) {
            $this->logger->debug('Skip post', ['reason' => 'marked as ads', 'vk_id' => $postId]);
            throw new PostFilterException('Post marked as ads');
        }

        if ($this->hasIgnoreTag($vkItem['text'])) {
            $this->logger->debug('Skip post', ['reason' => 'tagged with ignore tag', 'vk_id' => $postId]);
            throw new PostFilterException('Post tagged with ignore tag');
        }

        if (isset($vkItem['copy_history'])) {
            throw new PostFilterException('Reposts are not supported yet');
        }
    }

    private function hasIgnoreTag(string $text): bool
    {
        return (bool)preg_match(
            '/(?:^|\s)' . preg_quote($this->config->ignoreTag, '/') . '(?:\s|$)/i',
            $text
        );
    }
}
