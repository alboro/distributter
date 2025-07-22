<?php

declare(strict_types=1);

namespace Sc\Channels\Tg;

use danog\MadelineProto\API;
use Psr\Log\LoggerInterface;
use Sc\Channels\RetrieverInterface;
use Sc\Config\AppConfig;
use Sc\Model\{Post, PostId, PostIdCollection};
use Sc\Service\Repository;

/**
 * Извлекает и обрабатывает посты из Telegram через MadelineProto API
 * @todo: every got Sc\Model\Post must not know anything about by what count of separated ids it is stored in.
 */
readonly class TelegramRetriever implements RetrieverInterface
{
    public function __construct(
        private API $madelineProto,
        private AppConfig $config,
        private LoggerInterface $logger,
        private Repository $storage,
        private string $systemName,
    ) {}

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function channelId(): string
    {
        return $this->config->tgRetrieverChannel;
    }

    /**
     * Получает посты из Telegram
     *
     * @return Post[]
     */
    public function retrievePosts(): array
    {
        if (empty($this->config->tgRetrieverChannel)) {
            $this->logger->warning('TG_RETRIEVER_CHANNEL_ID not configured, skipping Telegram retrieval');
            return [];
        }

        try {
            $messages = $this->fetchTelegramMessages();
            return $this->processTelegramMessages($messages);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve Telegram messages', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    private function fetchTelegramMessages(): array
    {
        try {
            // Сначала пытаемся получить сообщения напрямую
            $response = $this->madelineProto->messages->getHistory([
                'peer' => $this->config->tgRetrieverChannel,
                'limit' => $this->config->itemCount,
            ]);

            return $response['messages'] ?? [];

        } catch (\Throwable $e) {
            return $this->handlePeerDatabaseException($e);
        }
    }

    /**
     * Обрабатывает исключения связанные с базой данных пиров
     */
    private function handlePeerDatabaseException(\Throwable $e): array
    {
        if (strpos($e->getMessage(), 'This peer is not present in the internal peer database') !== false) {
            $this->logger->warning('Канал не найден в базе пиров, пытаемся обновить базу данных пиров');

            // Пытаемся обновить базу данных пиров
            if ($this->updatePeerDatabase()) {
                // Повторная попытка после обновления базы пиров
                try {
                    $response = $this->madelineProto->messages->getHistory([
                        'peer' => $this->config->tgRetrieverChannel,
                        'limit' => $this->config->itemCount,
                    ]);

                    return $response['messages'] ?? [];

                } catch (\Throwable $retryError) {
                    $this->logger->error('Канал недоступен даже после обновления базы пиров', [
                        'channel' => $this->config->tgRetrieverChannel,
                        'error' => $retryError->getMessage()
                    ]);
                }
            }

            $this->logger->error('Канал недоступен', [
                'channel' => $this->config->tgRetrieverChannel,
                'reason' => 'Канал не найден в базе данных пиров MadelineProto',
                'solution' => 'Добавьте аккаунт в приватный канал как участника'
            ]);
        }

        throw new \Exception(
            "Канал '{$this->config->tgRetrieverChannel}' недоступен. " .
            "Для доступа к приватному каналу аккаунт должен быть его участником. " .
            "Добавьте аккаунт в канал или используйте публичный username канала."
        );
    }

    /**
     * Обновляет базу данных пиров MadelineProto
     */
    private function updatePeerDatabase(): bool
    {
        try {
            $this->logger->debug('Обновляем базу данных пиров MadelineProto');

            // Получаем список диалогов для обновления базы пиров
            $dialogs = $this->madelineProto->messages->getDialogs([
                'offset_date' => 0,
                'offset_id' => 0,
                'offset_peer' => ['_' => 'inputPeerEmpty'],
                'limit' => 100,
            ]);

            $this->logger->debug('База пиров обновлена', [
                'dialogs_count' => count($dialogs['dialogs'] ?? [])
            ]);

            // Проверяем, есть ли наш канал в списке диалогов
            $channelId = $this->config->tgRetrieverChannel;
            $numericChannelId = str_replace('-100', '', $channelId);

            foreach ($dialogs['chats'] ?? [] as $chat) {
                if ($chat['id'] == $numericChannelId) {
                    $this->logger->info('Канал найден в списке диалогов', [
                        'channel_id' => $channelId,
                        'title' => $chat['title'] ?? 'Unknown'
                    ]);
                    return true;
                }
            }

            $this->logger->debug('Канал не найден в диалогах', [
                'channel_id' => $channelId,
                'chats_count' => count($dialogs['chats'] ?? [])
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Ошибка обновления базы данных пиров', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * @return Post[]
     */
    private function processTelegramMessages(array $messages): array
    {
        $posts = [];

        // Обрабатываем сообщения в обратном порядке (от старых к новым)
        foreach (array_reverse($messages) as $message) {
            try {
                if ($post = $this->convertTelegramMessageToPost($message)) {
                    $posts[] = $post;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error processing Telegram message', [
                    'message_id' => $message['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // todo: $posts теперь содержат посты, но особенность телеги такова, что логически одна публикация пост может быть опубликован через несколько постов:
        //  поэтому нужно объединить посты, у которых $post->ids->getSystems() отличные от $this->systemName() и у которых хоть одна такая отличная systemName равна systemName из другого поста в массиве $posts

        return $posts;
    }

    private function convertTelegramMessageToPost(array $message): ?Post
    {
        $messageId = (string) $message['id'];
        $text = $message['message'] ?? '';

        // Парсим медиа вложения
        $photos = $this->parsePhotos($message);
        $videos = $this->parseVideos($message);
        $links = $this->parseLinks($text);

        // Пропускаем сообщения без контента (ни текста, ни медиа)
        if (empty($text) && empty($photos) && empty($videos)) {
            return null;
        }

        // Пропускаем сообщения с игнор-тегом (только если есть текст для проверки)
        if (!empty($text) && $this->hasIgnoreTag($text)) {
            $this->logger->debug('Skip message with ignore tag', ['message_id' => $message['id']]);
            return null;
        }

        $postId = new PostId($messageId, $this->systemName);
        $collection = $this->storage->find($postId) ?? (new PostIdCollection())->add($postId);

        return new Post(
            ids: $collection,
            text: $text,
            videos: $videos,
            links: $links,
            photos: $photos,
            author: null, // Telegram не предоставляет информацию об авторе в каналах
        );
    }

    private function hasIgnoreTag(string $text): bool
    {
        if (null === $this->config->ignoreTag) {
            return false;
        }
        return (bool)preg_match(
            '/(?:^|\s)' . preg_quote($this->config->ignoreTag, '/') . '(?:\s|$)/i',
            $text
        );
    }

    /**
     * @return string[]
     */
    private function parsePhotos(array $message): array
    {
        $photos = [];

        if (isset($message['media']) && $message['media']['_'] === 'messageMediaPhoto') {
            // Получаем наибольшее фото
            $photoSizes = $message['media']['photo']['sizes'] ?? [];
            $largestPhoto = null;
            $maxSize = 0;

            foreach ($photoSizes as $size) {
                // Ищем размер с максимальным разрешением
                if (isset($size['w'], $size['h']) && ($size['w'] * $size['h']) > $maxSize) {
                    $largestPhoto = $size;
                    $maxSize = $size['w'] * $size['h'];
                }
            }

            // Если не нашли размер с w/h, берем любой не stripped размер
            if (!$largestPhoto) {
                foreach ($photoSizes as $size) {
                    if (isset($size['type']) && $size['type'] !== 'i') { // 'i' - это stripped size
                        $largestPhoto = $size;
                        break;
                    }
                }
            }

            // Получаем реальный URL фото через MadelineProto
            if ($largestPhoto) {
                try {
                    // Используем downloadToDir для получения локального файла
                    $tempDir = sys_get_temp_dir();
                    $filename = 'telegram_photo_' . $message['id'] . '_' . time() . '.jpg';
                    $localPath = $tempDir . '/' . $filename;

                    // Скачиваем файл
                    $result = $this->madelineProto->downloadToFile($message['media'], $localPath);

                    if ($result && file_exists($localPath)) {
                        $photos[] = $localPath; // Возвращаем путь к локальному файлу
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to download photo from MadelineProto', [
                        'message_id' => $message['id'],
                        'error' => $e->getMessage()
                    ]);
                    // Fallback: пропускаем фото для этого сообщения
                }
            }
        }

        return $photos;
    }

    /**
     * @return string[]
     */
    private function parseVideos(array $message): array
    {
        $videos = [];

        if (isset($message['media']) && in_array($message['media']['_'], ['messageMediaDocument', 'messageMediaVideo'])) {
            // В реальной реализации здесь нужно получить URL видео через MadelineProto
            $videos[] = 'telegram_video_' . $message['id'];
        }

        return $videos;
    }

    /**
     * @return string[]
     */
    private function parseLinks(string $text): array
    {
        $links = [];

        // Простой парсинг URL из текста
        if (preg_match_all('/(https?:\/\/[^\s]+)/i', $text, $matches)) {
            $links = $matches[1];
        }

        return $links;
    }
}
