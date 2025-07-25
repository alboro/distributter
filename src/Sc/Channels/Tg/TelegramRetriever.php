<?php

declare(strict_types=1);

namespace Sc\Channels\Tg;

use danog\MadelineProto\API;
use Psr\Log\LoggerInterface;
use Sc\Channels\RetrieverInterface;
use Sc\Config\AppConfig;
use Sc\Model\{Post, PostId, PostIdCollection};
use Sc\Service\Repository;
use Sc\Service\MadelineProtoFixer;

/**
 * Extracts and processes posts from Telegram via MadelineProto API
 * @todo: every got Sc\Model\Post must not know anything about by what count of separated ids it is stored in.
 */
readonly class TelegramRetriever implements RetrieverInterface
{
    private MadelineProtoFixer $fixer;

    public function __construct(
        private API $madelineProto,
        private AppConfig $config,
        private LoggerInterface $logger,
        private Repository $storage,
        private string $systemName,
    ) {
        $this->fixer = new MadelineProtoFixer($this->logger, dirname($config->tgRetrieverSessionFile));
    }

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function channelId(): string
    {
        return $this->config->tgRetrieverChannel;
    }

    /**
     * Gets posts from Telegram
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
            // First try to get messages directly
            $response = $this->getHistory();

            return $response['messages'] ?? [];

        } catch (\Throwable $e) {
            // Check if this is a MadelineProto IPC error
            if (MadelineProtoFixer::isMadelineProtoIpcException($e)) {
                $this->logger->warning('Detected MadelineProto IPC issue, attempting automatic fix', [
                    'error' => $e->getMessage()
                ]);

                // Try to automatically fix the issue
                if ($this->fixer->fixIssues()) {
                    $this->logger->info('MadelineProto issues fixed, retrying operation');

                    // Give some time for restart after fix
                    sleep(3);

                    try {
                        // Retry after fix
                        $response = $this->getHistory();

                        $this->logger->info('Successfully retrieved messages after fixing MadelineProto issues');
                        return $response['messages'] ?? [];

                    } catch (\Throwable $retryError) {
                        $this->logger->error('Still failing after MadelineProto fix attempt', [
                            'original_error' => $e->getMessage(),
                            'retry_error' => $retryError->getMessage()
                        ]);

                        // If this is still a peer issue, handle as usual
                        return $this->handlePeerDatabaseException($retryError);
                    }
                } else {
                    $this->logger->error('Failed to automatically fix MadelineProto issues');
                }
            }

            // Handle other errors (including peer issues)
            return $this->handlePeerDatabaseException($e);
        }
    }

    /**
     * Handles exceptions related to peer database
     */
    private function handlePeerDatabaseException(\Throwable $e): array
    {
        if (strpos($e->getMessage(), 'This peer is not present in the internal peer database') !== false) {
            $this->logger->warning('Channel not found in peer database, trying to update peer database');

            // Try to update peer database
            if ($this->updatePeerDatabase()) {
                // Retry after updating peer database
                try {
                    $response = $this->madelineProto->messages->getHistory([
                        'peer' => $this->config->tgRetrieverChannel,
                        'limit' => $this->config->itemCount,
                    ]);

                    return $response['messages'] ?? [];

                } catch (\Throwable $retryError) {
                    $this->logger->error('Channel unavailable even after updating peer database', [
                        'channel' => $this->config->tgRetrieverChannel,
                        'error' => $retryError->getMessage()
                    ]);
                }
            }

            $this->logger->error('Channel unavailable', [
                'channel' => $this->config->tgRetrieverChannel,
                'reason' => 'Channel not found in MadelineProto peer database',
                'solution' => 'Add account to private channel as participant'
            ]);
        }

        throw new \Exception(
            "Channel '{$this->config->tgRetrieverChannel}' is unavailable. " .
            "To access a private channel, the account must be its participant. " .
            "Add the account to the channel or use the public username of the channel."
        );
    }

    private function getHistory(): array
    {
        return $this->madelineProto->messages->getHistory([
            'peer' => $this->config->tgRetrieverChannel,
            'limit' => $this->config->itemCount,
        ]);
    }

    /**
     * Updates MadelineProto peer database
     */
    private function updatePeerDatabase(): bool
    {
        try {
            $this->logger->debug('Updating MadelineProto peer database');

            // Get dialogs list to update peer database
            $dialogs = $this->madelineProto->messages->getDialogs([
                'offset_date' => 0,
                'offset_id' => 0,
                'offset_peer' => ['_' => 'inputPeerEmpty'],
                'limit' => 100,
            ]);

            $this->logger->debug('Peer database updated', [
                'dialogs_count' => count($dialogs['dialogs'] ?? [])
            ]);

            // Check if our channel is in the dialogs list
            $channelId = $this->config->tgRetrieverChannel;
            $numericChannelId = str_replace('-100', '', $channelId);

            foreach ($dialogs['chats'] ?? [] as $chat) {
                if ($chat['id'] == $numericChannelId) {
                    $this->logger->info('Channel found in dialogs list', [
                        'channel_id' => $channelId,
                        'title' => $chat['title'] ?? 'Unknown'
                    ]);
                    return true;
                }
            }

            $this->logger->debug('Channel not found in dialogs', [
                'channel_id' => $channelId,
                'chats_count' => count($dialogs['chats'] ?? [])
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Error updating peer database', [
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
                    // Use downloadToDir to get local file
                    $tempDir = sys_get_temp_dir();
                    $filename = 'telegram_photo_' . $message['id'] . '_' . time() . '.jpg';
                    $localPath = $tempDir . '/' . $filename;

                    // Download file
                    $result = $this->madelineProto->downloadToFile($message['media'], $localPath);

                    if ($result && file_exists($localPath)) {
                        $photos[] = $localPath; // Return path to local file
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to download photo from MadelineProto', [
                        'message_id' => $message['id'],
                        'error' => $e->getMessage()
                    ]);
                    // Fallback: skip photo for this message
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
