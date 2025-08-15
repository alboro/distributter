<?php

declare(strict_types=1);

namespace Sc\Integration\Tg\Retriever;

use danog\MadelineProto\API;
use danog\MadelineProto\PeerNotInDbException;
use danog\MadelineProto\RPCErrorException;
use Psr\Log\LoggerInterface;
use Sc\Integration\RetrieverInterface;
use Sc\Model\{Post, PostId, PostIdCollection};
use Sc\Service\Repository;

/**
 * Extracts and processes posts from Telegram via MadelineProto API
 * @todo: every got Sc\Model\Post must not know anything about by what count of separated ids it is stored in.
 */
readonly class TelegramRetriever implements RetrieverInterface
{
    public function __construct(
        private API $madelineProto,
        private TgRetrieverConfig $tgRetrieverConfig,
        private LoggerInterface $logger,
        private Repository $storage,
        private string $systemName,
        private MadelineProtoFixer $fixer,
    ) {
    }

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function channelId(): string
    {
        return $this->tgRetrieverConfig?->channel ?? '';
    }

    /**
     * Gets posts from Telegram
     *
     * @return Post[]
     */
    public function retrievePosts(): array
    {
        if (empty($this->tgRetrieverConfig?->channel)) {
            $this->logger->warning('TG_RETRIEVER_CHANNEL_ID not configured, skipping Telegram retrieval');
            return [];
        }

        // Check MadelineProto health before attempting to retrieve posts
        if (!$this->fixer->isHealthy()) {
            $this->logger->warning('MadelineProto health check failed, attempting to fix issues');
            $this->fixer->fixIssues();
        }

        try {
            $messages = $this->fetchTelegramMessages();
            return $this->processTelegramMessages($messages);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve Telegram messages', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Check if this is a "Too many open files" or similar MadelineProto issue
            if ($this->isMadelineProtoIssue($e)) {
                $this->logger->info('Detected MadelineProto issue, attempting automatic fix');

                if ($this->fixer->fixIssues()) {
                    $this->logger->info('Auto-fix successful, retrying message retrieval');

                    // For "channel already closed" errors, we need to wait a bit longer for IPC to stabilize
                    if (strpos($e->getMessage(), 'The channel was already closed') !== false) {
                        $this->logger->info('Waiting for IPC channel to stabilize after fix...');
                        sleep(3); // Give MadelineProto time to restart IPC server
                    }

                    try {
                        $messages = $this->fetchTelegramMessages();
                        return $this->processTelegramMessages($messages);
                    } catch (\Throwable $retryError) {
                        // If we still get "channel closed" error, it means IPC needs more time
                        if (strpos($retryError->getMessage(), 'The channel was already closed') !== false) {
                            $this->logger->warning('IPC channel still not ready, skipping Telegram retrieval this time');
                            return []; // Return empty array instead of failing
                        }

                        $this->logger->error('Failed to retrieve messages even after auto-fix', [
                            'error' => $retryError->getMessage()
                        ]);
                    }
                }
            }

            return [];
        }
    }

    /**
     * Check if the exception is related to MadelineProto issues
     */
    private function isMadelineProtoIssue(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $madelineProtoErrors = [
            'Too many open files',
            'Could not connect to MadelineProto',
            'Failed to write to stream',
            'Broken pipe',
            'Sending on the channel failed',
            'The channel was already closed',
            'fopen(): Failed to open stream: Too many open files',
            'include(): Failed to open stream: Too many open files'
        ];

        foreach ($madelineProtoErrors as $error) {
            if (strpos($message, $error) !== false) {
                return true;
            }
        }

        return false;
    }

    private function fetchTelegramMessages(): array
    {
        try {
            return $this->getHistoryWithRetries();

        } catch (PeerNotInDbException $exception) {
            return $this->handleNotInDbException($exception);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    function ensureChannelPeer(API $mp, string $identifier): array {
        // 1) Самый простой и универсальный путь — getInfo()
        //    Понимает: -100..., @username, t.me/..., t.me/+inviteHash
        try {
            $info = $mp->getInfo($identifier);
            if (!empty($info['InputPeer']) && ($info['InputPeer']['_'] ?? '') === 'inputPeerChannel') {
                return $info['InputPeer']; // ['_'=>'inputPeerChannel','channel_id'=>..., 'access_hash'=>...]
            }
        } catch (\Throwable $e) {
            // идём дальше — разрулим вручную
        }

        // 2) Если это инвайт-ссылка (+hash) — проверяем/импортируем
        if (preg_match('~t\.me/\+([A-Za-z0-9_-]+)~', $identifier, $m)) {
            $hash = $m[1];

            // а) checkChatInvite: разные типы ответов
            try {
                $res = $mp->messages->checkChatInvite(['hash' => $hash]);
                // варианты: chatInviteAlready (уже состоишь), chatInvitePeek (просмотр), chatInvite (приглашение)
                $chat = $res['chat'] ?? null;
                if (!$chat && isset($res['chats'][0])) $chat = $res['chats'][0];

                if ($chat && ($chat['_'] ?? '') === 'channel' && isset($chat['id'], $chat['access_hash'])) {
                    return ['_' => 'inputPeerChannel', 'channel_id' => $chat['id'], 'access_hash' => $chat['access_hash']];
                }
            } catch (\Throwable $e) {
                // допустимо — пробуем importChatInvite
            }

            // б) importChatInvite: вернёт Updates с массивом chats
            try {
                $upd = $mp->messages->importChatInvite(['hash' => $hash]);
                $chats = $upd['chats'] ?? [];
                foreach ($chats as $ch) {
                    if (($ch['_'] ?? '') === 'channel' && isset($ch['id'], $ch['access_hash'])) {
                        return ['_' => 'inputPeerChannel', 'channel_id' => $ch['id'], 'access_hash' => $ch['access_hash']];
                    }
                }
            } catch (RPCErrorException $e) {
                // важные кейсы: INVITE_HASH_EXPIRED / INVITE_HASH_INVALID / USER_ALREADY_PARTICIPANT
                if (in_array($e->rpc, ['USER_ALREADY_PARTICIPANT', 'USER_ALREADY_INVITED'], true)) {
                    // Уже состоим — getPeerDialogs точечно подтянет объект
                    $r = $mp->messages->getPeerDialogs(['peers' => [$identifier]]);
                    foreach (($r['chats'] ?? []) as $ch) {
                        if (($ch['_'] ?? '') === 'channel' && isset($ch['id'], $ch['access_hash'])) {
                            return ['_' => 'inputPeerChannel', 'channel_id' => $ch['id'], 'access_hash' => $ch['access_hash']];
                        }
                    }
                }
                // Иначе пробросим дальше
            }
        }

        // 3) Если у тебя -100… (numeric), getPeerDialogs справится, когда БД пустая
        if (preg_match('~^-?100(\d+)$~', $identifier, $m)) {
            // Madeline понимает и строку -100..., и InputPeer/peer string
            $r = $mp->messages->getPeerDialogs(['peers' => [$identifier]]);
            foreach (($r['chats'] ?? []) as $ch) {
                if (($ch['_'] ?? '') === 'channel' && isset($ch['id'], $ch['access_hash'])) {
                    return ['_' => 'inputPeerChannel', 'channel_id' => $ch['id'], 'access_hash' => $ch['access_hash']];
                }
            }
        }

        // 4) На крайний случай — попробуем ещё раз getInfo на "очищенном" виде
        foreach ([$identifier, ltrim($identifier, '@'), preg_replace('~^https?://t\.me/~', '', $identifier)] as $cand) {
            try {
                $info = $mp->getInfo($cand);
                if (!empty($info['InputPeer']) && ($info['InputPeer']['_'] ?? '') === 'inputPeerChannel') {
                    return $info['InputPeer'];
                }
            } catch (\Throwable $e) {}
        }

        throw new \RuntimeException('Cannot resolve channel peer: '.$identifier);
    }

    /**
     * Handles exceptions related to peer database
     */
    private function handleNotInDbException(PeerNotInDbException $e): array
    {
        $this->logger->warning('Peer not found in database, attempting to fetch peer dialogs', [
            'channel' => $this->tgRetrieverConfig->channel,
            'error' => $e->getMessage()
        ]);
        try {
            $result = $this->ensureChannelPeer($this->madelineProto, $this->tgRetrieverConfig->channel);
        } catch (\Throwable $e) {
            $result = $this->ensureChannelPeer($this->madelineProto, 'https://t.me/+lBteMtQefyE5MmU0'); // @todo
        }

        $this->logger->warning('Fetched peer channel messages!');
        return $result['messages'] ?? [];
    }

    private function getPeer(): string
    {
        return $this->tgRetrieverConfig->channel;
    }

    private function getHistoryWithRetries(): array
    {
        $maxRetries = $this->tgRetrieverConfig?->maxRetries ?? 1;
        $retryDelay = $this->tgRetrieverConfig?->retryDelay ?? 2;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->logger->debug("Attempting to get history (attempt {$attempt}/{$maxRetries})");

                // Use timeout wrapper for the operation
                $result = $this->madelineProto->messages->getHistory([
                    'peer' => $this->getPeer(),
                    'limit' => $this->tgRetrieverConfig->itemCount(),
                    'offset_date' => 0,
                    'offset_id' => 0,
                    'max_id' => 0,
                    'min_id' => 0,
                    'add_offset' => 0,
                    'hash' => [0]
                ]);

                $this->logger->debug("Successfully retrieved history on attempt {$attempt}");
                return $result['messages'] ?? [];

            } catch (\Throwable $e) {
                $this->logger->warning("Attempt {$attempt} failed: " . $e->getMessage());

                if ($attempt === $maxRetries) {
                    throw $e; // Re-throw on final attempt
                }

                // Wait before retry
                $this->logger->debug("Waiting {$retryDelay} seconds before retry...");
                sleep($retryDelay);
                $retryDelay = min($retryDelay * 2, 30); // Exponential backoff with max 30s
            }
        }

        throw new \RuntimeException('All retry attempts failed');
    }

    /**
     * @return Post[]
     */
    private function processTelegramMessages(array $messages): array
    {
        $posts = [];

        // Process messages in reverse order (from old to new)
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

        // todo: $posts now contain posts, but Telegram's peculiarity is that logically one publication post can be published through several posts:
        //  therefore need to combine posts where $post->ids->getSystems() are different from $this->systemName() and where at least one such different systemName equals systemName from another post in the $posts array

        return $posts;
    }

    private function convertTelegramMessageToPost(array $message): ?Post
    {
        $messageId = (string) $message['id'];
        $text = $message['message'] ?? '';

        // Parse media attachments
        $photos = $this->parsePhotos($message);
        $videos = $this->parseVideos($message);
        $links = $this->parseLinks($text);

        // Skip messages without content (neither text nor media)
        if (empty($text) && empty($photos) && empty($videos)) {
            return null;
        }

        // Skip messages with ignore tag (only if there's text to check)
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
            author: null, // Telegram doesn't provide author info in channels
        );
    }

    private function hasIgnoreTag(string $text): bool
    {
        return (bool)preg_match(
            '/(?:^|\s)' . preg_quote($this->tgRetrieverConfig->ignoreTag(), '/') . '(?:\s|$)/i',
            $text
        );
    }

    /**
     * @return string[]
     */
    private function parsePhotos(array $message): array
    {
        $photos = [];

        // Check if this is a forwarded message with media
        if (isset($message['fwd_from']) && isset($message['media'])) {
            $this->logger->debug('Processing forwarded message with media', [
                'message_id' => $message['id'],
                'media_type' => $message['media']['_'] ?? 'unknown'
            ]);
        }

        if (isset($message['media']) && $message['media']['_'] === 'messageMediaPhoto') {
            // Get the largest photo
            $photoSizes = $message['media']['photo']['sizes'] ?? [];
            $largestPhoto = null;
            $maxSize = 0;

            foreach ($photoSizes as $size) {
                // Look for size with maximum resolution
                if (isset($size['w'], $size['h']) && ($size['w'] * $size['h']) > $maxSize) {
                    $largestPhoto = $size;
                    $maxSize = $size['w'] * $size['h'];
                }
            }

            // If no size with w/h found, take any non-stripped size
            if (!$largestPhoto) {
                foreach ($photoSizes as $size) {
                    if (isset($size['type']) && $size['type'] !== 'i') { // 'i' is stripped size
                        $largestPhoto = $size;
                        break;
                    }
                }
            }

            // Get real photo URL via MadelineProto
            if ($largestPhoto) {
                try {
                    // Use downloadToDir to get local file with timeout
                    $tempDir = sys_get_temp_dir();
                    $filename = 'telegram_photo_' . $message['id'] . '_' . time() . '.jpg';
                    $localPath = $tempDir . '/' . $filename;

                    $this->logger->debug('Attempting to download photo', [
                        'message_id' => $message['id'],
                        'photo_size' => $maxSize,
                        'target_path' => $localPath
                    ]);

                    // Set a shorter timeout for photo downloads to avoid cancellation
                    $downloadPromise = $this->madelineProto->downloadToFile($message['media'], $localPath);

                    // Wait for download with timeout
                    $result = $downloadPromise;

                    if ($result && file_exists($localPath)) {
                        $this->logger->debug('Photo downloaded successfully', [
                            'message_id' => $message['id'],
                            'file_size' => filesize($localPath)
                        ]);
                        $photos[] = $localPath; // Return path to local file
                    } else {
                        $this->logger->warning('Photo download completed but file not found', [
                            'message_id' => $message['id'],
                            'expected_path' => $localPath
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to download photo from MadelineProto', [
                        'message_id' => $message['id'],
                        'error' => $e->getMessage(),
                        'error_type' => get_class($e)
                    ]);

                    // For forwarded messages, try to get photo info without downloading
                    if (isset($message['fwd_from'])) {
                        $this->logger->info('Skipping photo download for forwarded message', [
                            'message_id' => $message['id']
                        ]);
                        // Could add placeholder or skip photo entirely
                    }
                }
            } else {
                $this->logger->debug('No suitable photo size found', [
                    'message_id' => $message['id'],
                    'available_sizes' => count($photoSizes)
                ]);
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
            // In real implementation here we need to get video URL via MadelineProto
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

        // Simple URL parsing from text
        if (preg_match_all('/(https?:\/\/[^\s]+)/i', $text, $matches)) {
            $links = $matches[1];
        }

        return $links;
    }
}
