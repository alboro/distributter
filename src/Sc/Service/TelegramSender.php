<?php

declare(strict_types=1);

namespace Sc\Service;

use TelegramBot\Api\BotApi;
use Psr\Log\LoggerInterface;
use Sc\Storage;

readonly class TelegramSender
{
    private const string CAPTION_TOO_LONG_ERROR = 'Bad Request: MEDIA_CAPTION_TOO_LONG';

    public function __construct(
        private BotApi $tgBot,
        private string $channelId,
        private bool $useTgApi,
        private bool $enableNotification,
        private LoggerInterface $logger,
        private Storage $storage
    ) {}

    public function sendPhoto(int $vkItemId, string $photoUrl, string $caption): void
    {
        if (!$this->useTgApi) {
            $this->logDryRun('Send photo', $vkItemId, $caption, $photoUrl);
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

    public function sendMessage(int $vkItemId, string $text): void
    {
        if (empty($text)) {
            return;
        }

        if (!$this->useTgApi) {
            $this->logDryRun('Send message', $vkItemId, $text);
            return;
        }

        try {
            $message = $this->tgBot->sendMessage(
                chatId: $this->channelId,
                text: $text,
                parseMode: 'html',
                disablePreview: false,
                replyToMessageId: null,
                replyMarkup: null,
                disableNotification: !$this->enableNotification
            );

            $this->handleSuccessfulSend($vkItemId, $message->getMessageId(), $text);
        } catch (\Throwable $e) {
            $this->handleSendError($vkItemId, $e, ['text' => $text]);
        }
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

    private function handleSuccessfulSend(int $vkItemId, int $messageId, string $text, ?string $photo = null): void
    {
        $this->storage->addId($vkItemId, $messageId);

        $logAction = $photo !== null ? 'Send new photo' : 'Send new post';
        $context = [
            'id' => $vkItemId,
            'tgId' => $messageId,
            'text' => $text
        ];

        if ($photo !== null) {
            $context['photo'] = $photo;
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
