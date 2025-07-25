<?php

declare(strict_types=1);

namespace Sc\Channels\Vk;

use Psr\Log\LoggerInterface;
use Sc\Channels\SenderInterface;
use Sc\Config\VkSenderConfig;
use Sc\Dto\TransferPostDto;
use Sc\Model\Post;
use Sc\Model\PostId;
use Sc\Model\PostIdCollection;
use Sc\Service\StaticCache;
use Sc\Service\SuccessHook;
use VK\Client\VKApiClient;

readonly class VkSender implements SenderInterface
{
    // Limits for groups (more strict)
    private const int GROUP_MAX_POST_LENGTH = 2000;

    // Limits for public pages (more liberal)
    private const int PUBLIC_MAX_POST_LENGTH = 4096;  // Significantly higher for public pages
//    private const int PUBLIC_MAX_POST_LENGTH = 4623;  // Significantly higher for public pages

    private const int CONTINUATION_OVERLAP = 100; // Overlap between parts

    public function __construct(
        private VKApiClient              $vk,
        private VkSenderConfig           $config,
        private LoggerInterface          $logger,
        private SuccessHook              $successHook,
        private StaticCache              $cache,
        private string                   $systemName,
    ) {}

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function supportsPolls(): bool
    {
        return false; // VK Sender doesn't support polls yet. @todo: add support
    }

    /**
     * Sends post to VK, checking length limits
     */
    public function sendPost(TransferPostDto $transferPost): void
    {
        $formattedText = $this->cleanText($transferPost->post->text);

        // Use character count (mb_strlen) instead of byte count (strlen) for proper limit checking
        $textLength = mb_strlen($formattedText, 'UTF-8');
        $maxLength = $this->getMaxPostLength($transferPost->post);

        // Check text length limit
        if ($textLength > $maxLength) {
             $this->logger->warning('Post text exceeds length limit, skipping', [
                'text_length_chars' => $textLength,
                'text_length_bytes' => strlen($formattedText),
                'text' => mb_substr($formattedText, 0, 200, 'UTF-8') . '...', // Show only first 200 chars in log
                'max_allowed' => $maxLength,
                'post_id' => (string) $transferPost->post->ids,
                'original_length' => strlen($transferPost->post->text),
                'has_photo' => $transferPost->post->hasPhoto()
            ]);
            return;
        }

        try {
            if ($this->shouldSendAsPhoto($transferPost->post)) {
                $this->sendPhoto($transferPost, $formattedText);
                return;
            }
            $this->sendTextPost($transferPost, $formattedText);
        } catch (\Exception $e) {
            // Log error but don't stop execution
            $this->handleSendError($transferPost->post->ids, $e, [
                'text_length_chars' => $textLength,
                'text_length_bytes' => strlen($formattedText),
                'has_photo' => $transferPost->post->hasPhoto()
            ]);

            // Don't rethrow - let synchronization continue with other posts
            $this->logger->warning('Failed to send post, but continuing with synchronization', [
                'post_id' => (string) $transferPost->post->ids
            ]);
        }
    }

    private function shouldSendAsPhoto(Post $post): bool
    {
        return $post->hasPhoto();
    }

    private function sendPhoto(TransferPostDto $transferPost, string $formattedText): void
    {
        $photoUrl = $transferPost->post->hasPhoto() ? $transferPost->post->photos[0] : null;

        try {
            // First upload the photo
            $uploadedPhoto = $photoUrl ? $this->uploadPhoto($photoUrl) : [];

            // Then create post with photo
            $response = $this->vk->wall()->post($this->config->token, [
                'from_group' => true,
                'owner_id' => $this->config->groupId,
                'message' => $formattedText,
                'attachments' => $uploadedPhoto,
            ]);

            $newPostId = new PostId((string) $response['post_id'], $transferPost->otherSystemName);
            $transferPost->post->ids->add($newPostId);
            $this->successHook->handleSuccessfulSend($transferPost, $formattedText);

        } catch (\Exception $e) {
            $this->handleSendError(
                $transferPost->post->ids,
                $e,
                ['photo' => $photoUrl, 'message' => $formattedText]
            );
            // Don't rethrow - let synchronization continue
        }
    }

    private function sendTextPost(TransferPostDto $transferPost, string $formattedText): void
    {
        if (empty($formattedText)) {
            return;
        }

        $this->sendAsWallPost($transferPost, $formattedText);
    }

    /**
     * Sends regular text as wall post
     */
    private function sendAsWallPost(TransferPostDto $transferPost, string $formattedText): void
    {
        try {
            $response = $this->vk->wall()->post($this->config->token, [
                'from_group' => true,
                'owner_id' => $this->config->groupId,
                'message' => $formattedText,
            ]);

            $transferPost->post->ids->add(
                new PostId((string) $response['post_id'], $transferPost->otherSystemName)
            );
            $this->successHook->handleSuccessfulSend($transferPost, $formattedText);

        } catch (\Throwable $e) {
            $this->handleSendError($transferPost->post->ids, $e, [
                'text' => $formattedText,
                'method' => 'wall_post'
            ]);
            // Don't rethrow - let synchronization continue
        }
    }

    private function uploadPhoto(string $photoUrl): string
    {
        // Get server for photo upload
        $uploadServer = $this->vk->photos()->getWallUploadServer($this->config->token, [
            'group_id' => abs((int)$this->config->groupId),
        ]);

        // Download photo
        $photoData = file_get_contents($photoUrl);
        if ($photoData === false) {
            throw new \RuntimeException("Failed to download photo: $photoUrl");
        }

        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'vk_photo');
        file_put_contents($tempFile, $photoData);

        try {
            // Upload photo to VK server
            $uploadResponse = $this->uploadPhotoToVk($uploadServer['upload_url'], $tempFile);

            // Save photo
            $savedPhoto = $this->vk->photos()->saveWallPhoto($this->config->token, [
                'group_id' => abs((int)$this->config->groupId),
                'photo' => $uploadResponse['photo'],
                'server' => $uploadResponse['server'],
                'hash' => $uploadResponse['hash'],
            ]);

            return 'photo' . $savedPhoto[0]['owner_id'] . '_' . $savedPhoto[0]['id'];

        } finally {
            // Remove temporary file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    private function uploadPhotoToVk(string $uploadUrl, string $filePath): array
    {
        // Determine file MIME type
        $mimeType = mime_content_type($filePath) ?: 'image/jpeg';

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'photo' => new \CURLFile($filePath, $mimeType, 'photo.jpg'),
            ],
            CURLOPT_TIMEOUT => $this->config->requestTimeoutSec,
            CURLOPT_HTTPHEADER => [
                'User-Agent: VK_BOT_UPLOADER/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => false, // In case of SSL issues
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($httpCode !== 200 || $response === false) {
            throw new \RuntimeException("Failed to upload photo to VK: HTTP $httpCode, Error: $error");
        }

        $decoded = json_decode($response, true);
        if (!$decoded) {
            throw new \RuntimeException("Invalid JSON response from VK upload server: " . substr($response, 0, 100));
        }

        // Check that VK returned correct photo data
        if (!isset($decoded['photo']) || empty($decoded['photo']) || $decoded['photo'] === '[]') {
            throw new \RuntimeException("VK upload server returned empty photo data. Response: " . json_encode($decoded));
        }

        return $decoded;
    }

    /**
     * Determines if account is a public page or group via VK API
     */
    private function isPublicPage(): bool
    {
        $cacheKey = "vk_page_type_{$this->config->groupId}";

        // Check cache
        if ($this->cache->has($cacheKey)) {
            return (bool) $this->cache->get($cacheKey);
        }

        try {
            // Get page information via VK API
            $response = $this->vk->groups()->getById($this->config->token, [
                'group_id' => abs((int)$this->config->groupId),
                'fields' => 'type'
            ]);

            // Determine type: page = public page, group = group
            $isPublic = isset($response[0]['type']) && $response[0]['type'] === 'page';

            // Cache the result
            $this->cache->set($cacheKey, $isPublic);

            $this->logger->debug('Detected VK page type via API', [
                'group_id' => $this->config->groupId,
                'type' => $response[0]['type'] ?? 'unknown',
                'is_public' => $isPublic
            ]);

            return $isPublic;

        } catch (\Throwable $e) {
            $this->logger->warning('Failed to detect VK page type, falling back to group limits', [
                'group_id' => $this->config->groupId,
                'error' => $e->getMessage()
            ]);

            // In case of API error return false (use group limits as more strict)
            $this->cache->set($cacheKey, false);
            return false;
        }
    }

    /**
     * Gets maximum post length depending on account type and content type
     */
    private function getMaxPostLength(?Post $post = null): int
    {
        return $this->isPublicPage() ? self::PUBLIC_MAX_POST_LENGTH : self::GROUP_MAX_POST_LENGTH;
    }

    private function handleSendError(PostIdCollection $collection, \Throwable $e, array $context = []): void
    {
        $this->logger->error('send VK ' . (isset($context['photo']) ? 'Photo' : 'Post'), [
            'id' => (string) $collection,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            ...$context
        ]);
    }

    /**
     * Clean text from HTML tags, entities and invalid characters
     */
    private function cleanText(string $text): string
    {
        // First decode HTML entities (multiple passes to handle nested entities)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // Second pass for safety

        // Remove HTML/XML tags more aggressively
        $text = strip_tags($text);

        // Remove common markdown formatting that might be hidden
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text); // Bold **text**
        $text = preg_replace('/\*(.*?)\*/', '$1', $text); // Italic *text*
        $text = preg_replace('/__(.*?)__/', '$1', $text); // Underline __text__
        $text = preg_replace('/`(.*?)`/', '$1', $text); // Code `text`
        $text = preg_replace('/```.*?```/s', '', $text); // Code blocks

        // Remove URLs (they might be very long and hidden)
        $text = preg_replace('/https?:\/\/[^\s]+/i', '', $text);

        // Remove Telegram-specific formatting
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text); // [text](url)

        // Remove or replace problematic characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text); // Remove control characters
        $text = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $text); // Remove 4-byte UTF-8 sequences (emojis that might cause issues)

        // Remove zero-width characters and other invisible Unicode
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text); // Zero-width spaces
        $text = preg_replace('/[\x{2060}-\x{206F}]/u', '', $text); // Various invisible characters

        // Replace multiple whitespaces with single space, but preserve line breaks
        $text = preg_replace('/[ \t]+/', ' ', $text); // Only horizontal whitespace → single space
        $text = preg_replace('/\n{3,}/', "\n\n", $text); // Multiple line breaks → double line break

        // Trim whitespace from each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        // Trim overall whitespace
        $text = trim($text);

        // Ensure valid UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Debug: log the cleaning process
        $originalLength = strlen($text);
        if ($originalLength > 5000) {
            // Count different types of content
            $lines = explode("\n", $text);
            $words = str_word_count($text, 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ');
            $whitespaceCount = substr_count($text, ' ') + substr_count($text, "\n") + substr_count($text, "\t");

            $this->logger->debug('Text cleaning details', [
                'original_length' => $originalLength,
                'preview' => substr($text, 0, 200) . '...',
                'lines_count' => count($lines),
                'words_count' => $words,
                'whitespace_count' => $whitespaceCount,
                'avg_line_length' => count($lines) > 0 ? round($originalLength / count($lines), 1) : 0,
                'contains_urls' => (bool)preg_match('/https?:\/\//', $text),
                'contains_markdown' => (bool)preg_match('/[\*_`]/', $text),
                'contains_quotes' => substr_count($text, '"') + substr_count($text, '"') + substr_count($text, '"'),
                'actual_visual_length' => mb_strlen($text, 'UTF-8') // Character count vs byte count
            ]);

            // Log first few lines to see the structure
            $firstLines = array_slice($lines, 0, 5);
            $this->logger->debug('First 5 lines of long text', [
                'lines' => $firstLines,
                'line_lengths' => array_map('strlen', $firstLines)
            ]);
        }

        return $text;
    }
}
