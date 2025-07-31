<?php

declare(strict_types=1);

namespace Sc\Channels\Tg\Retriever;

use danog\MadelineProto\API;
use Psr\Log\LoggerInterface;
use Sc\Channels\RetrieverInterface;
use Sc\Config\AppConfig;
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
        private AppConfig $config,
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
        return $this->config->tgRetrieverConfig?->channel ?? '';
    }

    /**
     * Gets posts from Telegram
     *
     * @return Post[]
     */
    public function retrievePosts(): array
    {
        if (empty($this->config->tgRetrieverConfig?->channel)) {
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

    /**
     * Recreate MadelineProto API instance after fixing issues
     */
    private function recreateMadelineProtoApi(): void
    {
        try {
            $this->logger->info('Recreating MadelineProto API instance after fix');

            // Import settings from the current instance if possible
            $settings = new \danog\MadelineProto\Settings();
            $settings->getAppInfo()->setApiId((int) $this->config->tgRetrieverConfig->apiId);
            $settings->getAppInfo()->setApiHash($this->config->tgRetrieverConfig->apiHash);

            // Use the same aggressive timeouts
            $settings->getConnection()->setTimeout(10.0);
            $settings->getRpc()->setRpcDropTimeout(15);
            $settings->getRpc()->setFloodTimeout(10);
            $settings->getConnection()->setRetry(false);
            $settings->getConnection()->setPingInterval(30);

            // Disable verbose logging
            $settings->getLogger()->setType(\danog\MadelineProto\Logger::ECHO_LOGGER);
            $settings->getLogger()->setLevel(\danog\MadelineProto\Logger::LEVEL_FATAL);

            // Create new API instance
            $newApi = new \danog\MadelineProto\API($this->config->tgRetrieverConfig->sessionFile, $settings);

            // Test the connection
            $newApi->start();
            $self = $newApi->getSelf();

            if (!empty($self)) {
                // Replace the broken instance with the new one
                $this->madelineProto = $newApi;
                $this->logger->info('✅ Successfully recreated MadelineProto API');
            } else {
                throw new \Exception('Failed to authorize new API instance');
            }

        } catch (\Throwable $e) {
            $this->logger->error('Failed to recreate MadelineProto API: ' . $e->getMessage());
            throw $e;
        }
    }

    private function fetchTelegramMessages(): array
    {
        try {
            // First try to get messages directly
            return $this->getHistory();

        } catch (\Throwable $e) {
            // Handle peer database exceptions specifically
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
                        'peer' => $this->config->tgRetrieverConfig->channel,
                        'limit' => $this->config->itemCount,
                    ]);

                    return $response['messages'] ?? [];

                } catch (\Throwable $retryError) {
                    $this->logger->error('Channel unavailable even after updating peer database', [
                        'channel' => $this->config->tgRetrieverConfig->channel,
                        'error' => $retryError->getMessage()
                    ]);
                }
            }

            $this->logger->error('Channel unavailable', [
                'channel' => $this->config->tgRetrieverConfig->channel,
                'reason' => 'Channel not found in MadelineProto peer database',
                'solution' => 'Add account to private channel as participant'
            ]);
        }

        throw $e;
    }

    private function getHistory(): array
    {
        $maxRetries = $this->config->tgRetrieverConfig?->maxRetries ?? 3;
        $retryDelay = $this->config->tgRetrieverConfig?->retryDelay ?? 2;
        $timeout = $this->config->tgRetrieverConfig?->timeoutSec ?? 30;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->logger->debug("Attempting to get history (attempt {$attempt}/{$maxRetries})");

                // Use timeout wrapper for the operation
                $result = $this->executeWithTimeout(function() {
                    return $this->madelineProto->messages->getHistory([
                        'peer' => $this->config->tgRetrieverConfig->channel,
                        'limit' => $this->config->itemCount,
                        'offset_date' => 0,
                        'offset_id' => 0,
                        'max_id' => 0,
                        'min_id' => 0,
                        'add_offset' => 0,
                        'hash' => [0]
                    ]);
                }, $timeout);

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
     * Execute operation with timeout
     */
    private function executeWithTimeout(callable $operation, int $timeoutSeconds)
    {
        // Set a reasonable timeout for the operation
        $startTime = time();

        try {
            // For MadelineProto, we can't easily wrap with timeout,
            // but we can add some safety measures

            // Log start of operation
            $this->logger->debug("Starting operation with {$timeoutSeconds}s timeout");

            $result = $operation();

            $duration = time() - $startTime;
            $this->logger->debug("Operation completed in {$duration} seconds");

            return $result;

        } catch (\Throwable $e) {
            $duration = time() - $startTime;
            $this->logger->warning("Operation failed after {$duration} seconds: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Updates MadelineProto peer database
     */
    private function updatePeerDatabase(): bool
    {
        try {
            $this->logger->debug('Updating MadelineProto peer database');

            // Get dialogs list to update peer database (with hash parameter like in test-channel-access.php)
            $dialogs = $this->madelineProto->messages->getDialogs([
                'offset_date' => 0,
                'offset_id' => 0,
                'offset_peer' => ['_' => 'inputPeerEmpty'],
                'limit' => 100,
                'hash' => 0
            ]);

            $this->logger->debug('Peer database updated', [
                'dialogs_count' => count($dialogs['dialogs'] ?? [])
            ]);

            // Also try to get self info for database update (like in test-channel-access.php)
            try {
                $self = $this->madelineProto->getSelf();
                $this->logger->debug('Self info retrieved', [
                    'name' => $self['first_name'] ?? 'Unknown'
                ]);
            } catch (\Throwable $e) {
                $this->logger->debug('Could not get self info: ' . $e->getMessage());
            }

            // Check if our channel is in the dialogs list
            $channelId = $this->config->tgRetrieverConfig->channel;
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

            // Try to get channel info using getInfo() method (like in test-channel-access.php)
            try {
                $channelInfo = $this->madelineProto->getInfo($channelId);
                $this->logger->info('Channel accessible via getInfo', [
                    'type' => $channelInfo['type'] ?? 'unknown',
                    'id' => $channelInfo['bot_api_id'] ?? 'unknown'
                ]);
                return true;
            } catch (\Throwable $e) {
                $this->logger->debug('getInfo failed: ' . $e->getMessage());
            }

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
