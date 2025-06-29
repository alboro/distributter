<?php

declare(strict_types=1);

namespace Sc\Service;

use TelegramBot\Api\BotApi;
use Psr\Log\LoggerInterface;
use Sc\Storage;

readonly class TelegramSender
{
    private const string CAPTION_TOO_LONG_ERROR = 'Bad Request: MEDIA_CAPTION_TOO_LONG';
    private const int MAX_MESSAGE_LENGTH = 4000; // Оставляем запас от лимита Telegram в 4096

    public function __construct(
        private BotApi $tgBot,
        private string $channelId,
        private bool $useTgApi,
        private bool $enableNotification,
        private LoggerInterface $logger,
        private Storage $storage,
        private MessageFormatter $messageFormatter
    ) {}

    /**
     * Отправляет пост в Telegram, автоматически выбирая оптимальный формат
     */
    public function sendPost(int $vkItemId, string $text, array $videos, array $links, array $photos, ?string $author): void
    {
        // Форматируем сообщение
        $formattedText = $this->messageFormatter->formatMessage($text, $videos, $links, $photos, $author);

        // Пытаемся отправить как фото с подписью
        if ($this->messageFormatter->shouldSendAsPhoto($photos, $formattedText)) {
            try {
                $this->sendPhoto($vkItemId, $photos[0], $formattedText);
                return;
            } catch (\Exception $e) {
                if ($e->getMessage() !== self::CAPTION_TOO_LONG_ERROR) {
                    return;
                }
                // Если подпись слишком длинная, продолжаем и отправляем как обычное сообщение
            }
        }

        // Отправляем как текстовое сообщение
        $this->sendMessage($vkItemId, $formattedText);
    }

    public function sendPhoto(int $vkItemId, string $photoUrl, string $caption): void
    {
        if (!$this->useTgApi) {
            $this->logDryRun('Send photo', $vkItemId, $caption, $photoUrl);
            $this->handleSuccessfulSend($vkItemId, random_int(900, 9000), $caption, $photoUrl);
            return;
        }

        try {
            $message = $this->tgBot->sendPhoto(
                chatId: $this->channelId,
                photo: $photoUrl,
                caption: $caption,
                replyMarkup: null,
                replyToMessageId: null,
                disableNotification: !$this->enableNotification,
                parseMode: 'html'
            );

            $this->handleSuccessfulSend($vkItemId, $message->getMessageId(), $caption, $photoUrl);
        } catch (\Exception $e) {
            $this->handleSendError($vkItemId, $e, ['photo' => $photoUrl, 'caption' => $caption]);

            if ($e->getMessage() === self::CAPTION_TOO_LONG_ERROR) {
                throw $e;
            }
        }
    }

    private function sendMessage(int $vkItemId, string $text): void
    {
        if (empty($text)) {
            return;
        }

        // Разбиваем длинные сообщения на части с сохранением HTML структуры
        $messageParts = $this->splitMessageSafely($text);

        foreach ($messageParts as $index => $part) {
            if (!$this->useTgApi) {
                $this->logDryRun('Send message', $vkItemId, $part);
                $this->handleSuccessfulSend($vkItemId, random_int(900, 9000), $part, null, $index + 1, count($messageParts));
                continue;
            }

            try {
                $message = $this->tgBot->sendMessage(
                    chatId: $this->channelId,
                    text: $part,
                    parseMode: 'html',
                    disablePreview: false,
                    replyToMessageId: null,
                    replyMarkup: null,
                    disableNotification: !$this->enableNotification
                );

                $this->handleSuccessfulSend($vkItemId, $message->getMessageId(), $part, null, $index + 1, count($messageParts));
            } catch (\Throwable $e) {
                $this->handleSendError($vkItemId, $e, ['text' => $part, 'part' => $index + 1]);
            }
        }
    }

    /**
     * Разбивает длинное сообщение на части с сохранением HTML структуры
     */
    private function splitMessageSafely(string $text): array
    {
        if (strlen($text) <= self::MAX_MESSAGE_LENGTH) {
            return [$text];
        }

        $parts = [];
        $currentPart = '';
        $htmlStack = [];
        $i = 0;

        while ($i < strlen($text)) {
            $char = $text[$i];

            // Проверяем на HTML тег
            if ($char === '<') {
                $tagEnd = strpos($text, '>', $i);
                if ($tagEnd !== false) {
                    $tag = substr($text, $i, $tagEnd - $i + 1);

                    // Проверяем, поместится ли тег в текущую часть
                    if (strlen($currentPart . $tag) > self::MAX_MESSAGE_LENGTH) {
                        // Закрываем открытые теги в текущей части
                        $currentPart .= $this->closeOpenTags($htmlStack);
                        $parts[] = $currentPart;

                        // Начинаем новую часть с открытия тегов
                        $currentPart = $this->reopenTags($htmlStack);
                    }

                    $currentPart .= $tag;
                    $this->updateHtmlStack($tag, $htmlStack);
                    $i = $tagEnd + 1;
                    continue;
                }
            }

            // Обычный символ
            if (strlen($currentPart . $char) > self::MAX_MESSAGE_LENGTH) {
                // Пытаемся найти ближайший пробел для разрыва
                $breakPoint = $this->findSafeBreakPoint($currentPart);

                if ($breakPoint > 0) {
                    $partToSave = substr($currentPart, 0, $breakPoint);
                    $remainder = substr($currentPart, $breakPoint);

                    $partToSave .= $this->closeOpenTags($htmlStack);
                    $parts[] = $partToSave;

                    $currentPart = $this->reopenTags($htmlStack) . $remainder . $char;
                } else {
                    // Если не можем найти безопасную точку разрыва, принудительно разбиваем
                    $currentPart .= $this->closeOpenTags($htmlStack);
                    $parts[] = $currentPart;
                    $currentPart = $this->reopenTags($htmlStack) . $char;
                }
            } else {
                $currentPart .= $char;
            }

            $i++;
        }

        if (!empty($currentPart)) {
            $parts[] = $currentPart;
        }

        return array_filter($parts, fn($part) => !empty(trim($part)));
    }

    /**
     * Обновляет стек HTML тегов
     */
    private function updateHtmlStack(string $tag, array &$htmlStack): void
    {
        if (preg_match('/<\/(\w+)>/', $tag, $matches)) {
            // Закрывающий тег - удаляем из стека
            $tagName = $matches[1];
            $htmlStack = array_filter($htmlStack, fn($openTag) => !str_contains($openTag, $tagName));
        } elseif (preg_match('/<(\w+)(?:\s[^>]*)?>/', $tag, $matches) && !str_ends_with($tag, '/>')) {
            // Открывающий тег (не самозакрывающийся) - добавляем в стек
            $htmlStack[] = $tag;
        }
    }

    /**
     * Закрывает все открытые HTML теги
     */
    private function closeOpenTags(array $htmlStack): string
    {
        $closingTags = '';
        foreach (array_reverse($htmlStack) as $openTag) {
            if (preg_match('/<(\w+)/', $openTag, $matches)) {
                $closingTags .= '</' . $matches[1] . '>';
            }
        }
        return $closingTags;
    }

    /**
     * Переоткрывает HTML теги в новой части сообщения
     */
    private function reopenTags(array $htmlStack): string
    {
        return implode('', $htmlStack);
    }

    /**
     * Находит безопасную точку для разрыва сообщения (по пробелу или переносу строки)
     */
    private function findSafeBreakPoint(string $text): int
    {
        $length = strlen($text);
        $maxSearchBack = min(200, $length); // Ищем в последних 200 символах

        for ($i = $length - 1; $i >= $length - $maxSearchBack; $i--) {
            if (in_array($text[$i], [' ', "\n", "\t", '-', '–', '—'], true)) {
                return $i + 1;
            }
        }

        return 0; // Безопасная точка не найдена
    }

    private function logDryRun(string $action, int $vkItemId, string $text, ?string $photo = null): void
    {
        $context = [
            'id' => $vkItemId,
            'text' => $text
        ];

        if ($photo !== null) {
            $context['photo'] = $photo;
        }

        $this->logger->info("DRY RUN: $action", $context);
    }

    private function handleSuccessfulSend(
        int $vkItemId,
        int $messageId,
        string $text,
        ?string $photo = null,
        int $partNumber = 1,
        int $totalParts = 1
    ): void {
        $this->storage->addId($vkItemId, $messageId);

        $logAction = $photo !== null ? 'Send new photo' : 'Send new post';
        if ($totalParts > 1) {
            $logAction .= " (part $partNumber/$totalParts)";
        }

        $context = [
            'id' => $vkItemId,
            'tgId' => $messageId,
            'text' => $text
        ];

        if ($photo !== null) {
            $context['photo'] = $photo;
        }

        if ($totalParts > 1) {
            $context['part'] = "$partNumber/$totalParts";
        }

        $this->logger->info($logAction, $context);
    }

    private function handleSendError(int $vkItemId, \Throwable $e, array $context): void
    {
        $this->logger->error('send ' . (isset($context['photo']) ? 'Photo' : 'Message'), [
            'id' => $vkItemId,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            ...$context
        ]);
    }
}
