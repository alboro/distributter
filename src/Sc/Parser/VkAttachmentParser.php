<?php

declare(strict_types=1);

namespace Sc\Parser;

readonly class VkAttachmentParser
{
    public function __construct(
        private int $requestTimeoutSec = 30
    ) {}

    public function parsePhotos(array $vkItem): array
    {
        return array_map(
            fn(array $attachment) => end($attachment['photo']['sizes'])['url'],
            array_filter(
                $vkItem['attachments'] ?? [],
                fn(array $attachment) => $attachment['type'] === 'photo'
            )
        );
    }

    public function parseVideos(array $vkItem): array
    {
        $videoAttachments = array_filter(
            $vkItem['attachments'] ?? [],
            fn(array $attachment) => $attachment['type'] === 'video'
        );

        return array_map(
            fn(array $attachment) => $this->processVideoAttachment($attachment['video']),
            $videoAttachments
        );
    }

    public function parseLinks(array $vkItem): array
    {
        $linkAttachments = array_filter(
            $vkItem['attachments'] ?? [],
            fn(array $attachment) => $attachment['type'] === 'link'
        );

        return array_reduce(
            $linkAttachments,
            fn(array $carry, array $attachment) => [
                ...$carry,
                $attachment['link']['title'] => $attachment['link']['url']
            ],
            []
        );
    }

    public function validatePoll(array $vkItem): void
    {
        $pollAttachments = array_filter(
            $vkItem['attachments'] ?? [],
            fn(array $attachment) => $attachment['type'] === 'poll'
        );

        if (!empty($pollAttachments)) {
            throw new \RuntimeException('Poll is not supported yet');
        }
    }

    private function processVideoAttachment(array $video): string
    {
        $vkLink = sprintf('https://vk.com/video%s_%s', $video['owner_id'], $video['id']);

        if (isset($video['platform'])) {
            return $this->extractDirectVideoLink($vkLink) ?? $vkLink;
        }

        return $vkLink;
    }

    private function extractDirectVideoLink(string $vkLink): ?string
    {
        try {
            $context = stream_context_create([
                'http' => ['timeout' => $this->requestTimeoutSec]
            ]);

            $content = file_get_contents($vkLink, false, $context);
            if (!$content) {
                return null;
            }

            if (preg_match('/<iframe [^>]+src="([^"]+)\?/', $content, $matches)) {
                return str_replace('\\', '', $matches[1]);
            }
        } catch (\Throwable) {
            // Игнорируем ошибки и возвращаем null
        }

        return null;
    }
}
