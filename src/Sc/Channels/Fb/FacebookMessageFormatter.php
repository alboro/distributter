<?php

declare(strict_types=1);

namespace Sc\Channels\Fb;

use Sc\Model\Post;

class FacebookMessageFormatter
{
    private const int MAX_CAPTION_LENGTH = 2200;

    public function formatMessage(Post $post): string
    {
        $text = $post->text;

        // Убираем HTML теги, Facebook их не поддерживает в постах
        $text = strip_tags($text);

        // Декодируем HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Нормализуем переносы строк
        $text = preg_replace('/\r\n?/', "\n", $text);

        // Убираем лишние пробелы и переносы
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    public function shouldSendAsPhoto(array $photos, string $text): bool
    {
        if (empty($photos)) {
            return false;
        }

        if (mb_strlen($text) <= self::MAX_CAPTION_LENGTH) {
            return true;
        }

        return false;
    }

    public function truncateForCaption(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CAPTION_LENGTH) {
            return $text;
        }

        // Обрезаем с многоточием
        $truncated = mb_substr($text, 0, self::MAX_CAPTION_LENGTH - 3);

        // Пытаемся обрезать по последнему слову
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > self::MAX_CAPTION_LENGTH * 0.8) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }

    public function sanitizeText(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = str_replace(['"', '"', ''', '''], ['"', '"', "'", "'"], $text);
        $text = str_replace(['—', '–'], '-', $text);

        return $text;
    }

    public function extractHashtags(string $text): array
    {
        preg_match_all('/#[a-zA-Zа-яА-Я0-9_]+/u', $text, $matches);
        return $matches[0] ?? [];
    }

    public function formatWithHashtags(string $text, array $hashtags = null): string
    {
        if ($hashtags === null) {
            $hashtags = $this->extractHashtags($text);
        }

        if (empty($hashtags)) {
            return $text;
        }

        $cleanText = preg_replace('/#[a-zA-Zа-яА-Я0-9_]+\s*/u', '', $text);
        $cleanText = preg_replace('/\s+/', ' ', trim($cleanText));

        return $cleanText . "\n\n" . implode(' ', $hashtags);
    }
}
