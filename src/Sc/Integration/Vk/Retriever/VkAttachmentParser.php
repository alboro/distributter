<?php

declare(strict_types=1);

namespace Sc\Integration\Vk\Retriever;

use Sc\Model\Poll;

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

    public function parsePoll(array $vkItem): ?Poll
    {
        $pollAttachments = array_filter(
            $vkItem['attachments'] ?? [],
            fn(array $attachment) => $attachment['type'] === 'poll'
        );

        if (empty($pollAttachments)) {
            return null;
        }

        $pollData = reset($pollAttachments)['poll'];

        // Parse answer options
        $options = [];
        foreach ($pollData['answers'] ?? [] as $answer) {
            $options[] = [
                'text' => $answer['text'],
                'votes' => $answer['votes'] ?? 0,
                'id' => $answer['id'] ?? null,
            ];
        }

        return new Poll(
            question: $pollData['question'] ?? 'Poll',
            options: $options,
            totalVotes: $pollData['votes'] ?? null,
            isAnonymous: $pollData['anonymous'] ?? true,
            isMultipleChoice: $pollData['multiple'] ?? false,
            originalId: $pollData['id'] ?? null,
            endDate: $pollData['end_date'] ?? null,
        );
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
            // Ignore errors and return null
        }

        return null;
    }
}
