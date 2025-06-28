<?php

declare(strict_types=1);

namespace Sc\Service;

readonly class MessageFormatter
{
    private const int CAPTION_MAX_LENGTH = 1000;
    private const int LONG_TEXT_THRESHOLD = 500;
    private const string EXCLUDED_AUTHOR = 'Александр Демченко';

    public function formatMessage(
        string $text,
        array $videos,
        array $links,
        array $photos,
        ?string $author = null
    ): string {
        $text = $this->prependVideos($text, $videos);
        $text = $this->appendLinks($text, $links);

        return match (true) {
            $this->shouldSendAsPhoto($photos, $text) =>
                $this->formatPhotoCaption($text, $author),
            default =>
                $this->appendPhotos($text, $photos, $author)
        };
    }

    public function shouldSendAsPhoto(array $photos, string $text): bool
    {
        return count($photos) === 1 && strlen($text) < self::CAPTION_MAX_LENGTH;
    }

    public function validatePhotoPost(string $text, array $photos): void
    {
        if (empty($text) && !empty($photos)) {
            throw new \RuntimeException('Prevent sending photo with no text');
        }
    }

    private function prependVideos(string $text, array $videos): string
    {
        return array_reduce(
            $videos,
            fn(string $carry, string $url) => sprintf("<a href='%s'>%s</a>\n", $url, $url) . $carry,
            $text
        );
    }

    private function appendLinks(string $text, array $links): string
    {
        return array_reduce(
            array_keys($links),
            fn(string $carry, string $title) => $carry . sprintf("\n<a href='%s'>%s</a>", $links[$title], $title),
            $text
        );
    }

    private function formatPhotoCaption(string $text, ?string $author): string
    {
        return match (true) {
            $author !== null
                && $author !== self::EXCLUDED_AUTHOR
                && strlen($text) > self::LONG_TEXT_THRESHOLD =>
                $text . "\n© " . $author,
            default =>
                $text
        };
    }

    private function appendPhotos(string $text, array $photos, ?string $author): string
    {
        $useAuthorForFirstPhoto = $author !== null;

        return array_reduce(
            array_keys($photos),
            function (string $carry, int $index) use ($photos, &$useAuthorForFirstPhoto, $author): string {
                $pictureText = match (true) {
                    $useAuthorForFirstPhoto && $index === 0 => (function() use (&$useAuthorForFirstPhoto, $author) {
                        $useAuthorForFirstPhoto = false;
                        return $author;
                    })(),
                    default => sprintf('Изображение %d', $index + 1)
                };

                return $carry . sprintf("\n<a href='%s'>%s</a>", $photos[$index], $pictureText);
            },
            $text
        );
    }
}
