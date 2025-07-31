        // Create ID collection
<?php

declare(strict_types=1);

        // Create post with correct parameters

use Psr\Log\LoggerInterface;
use Sc\Channels\RetrieverInterface;
use Sc\Config\AppConfig;
            links: [], // Facebook API can provide links, but not processing them yet
use Sc\Model\PostId;
            author: null, // Can be obtained via additional request
use Sc\Service\Repository;

readonly class FacebookRetriever implements RetrieverInterface
{
    private const string FB_GRAPH_API_URL = 'https://graph.facebook.com/v18.0';

    public function __construct(
        private string          $accessToken,
        private string          $pageId,
        private AppConfig       $config,
        private LoggerInterface $logger,
        private Repository      $storage,
        private string          $systemName,
        private int             $requestTimeoutSec = 30,
    ) {}

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function channelId(): string
    {
        return $this->pageId;
    }

    /**
     * Получает посты из Facebook страницы
     */
    public function retrievePosts(): array
    {
        try {
            $posts = $this->fetchFacebookPosts();
            $this->logger->debug('Facebook posts retrieved', [
                'count' => count($posts),
                'page_id' => $this->pageId
            ]);
            return $posts;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve Facebook posts', [
                'error' => $e->getMessage(),
                'page_id' => $this->pageId
            ]);
            return [];
        }
    }

    /**
     * Получает посты через Facebook Graph API
     */
    private function fetchFacebookPosts(): array
    {
        $url = self::FB_GRAPH_API_URL . "/{$this->pageId}/posts";

        $params = [
            'fields' => 'id,message,full_picture,attachments{media,url},created_time,permalink_url',
            'limit' => $this->config->itemCount,
            'access_token' => $this->accessToken
        ];

        $response = $this->makeApiRequest($url, $params);

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new \Exception('Invalid response format from Facebook API');
        }

        $posts = [];
        foreach ($response['data'] as $fbPost) {
            try {
                $post = $this->convertFacebookPostToPost($fbPost);
                if ($post !== null) {
                    $posts[] = $post;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to convert Facebook post', [
                    'post_id' => $fbPost['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $posts;
    }

    /**
     * Конвертирует пост Facebook в объект Post
     */
    private function convertFacebookPostToPost(array $fbPost): ?Post
    {
        $postId = $fbPost['id'] ?? null;
        if (!$postId) {
            return null;
        }

        $text = $fbPost['message'] ?? '';
        $photos = [];
        $videos = [];

        // Обрабатываем изображения
        if (!empty($fbPost['full_picture'])) {
            $photos[] = $fbPost['full_picture'];
        }

        // Обрабатываем вложения
        if (isset($fbPost['attachments']['data'])) {
            foreach ($fbPost['attachments']['data'] as $attachment) {
                if (isset($attachment['media']['image']['src'])) {
                    $photos[] = $attachment['media']['image']['src'];
                }
                // Можно добавить обработку видео, если необходимо
            }
        }

        // Убираем дубликаты фото
        $photos = array_unique($photos);

        // Создаем коллекцию ID
        $ids = new PostIdCollection([
            new PostId($postId, $this->systemName)
        ]);

        // Создаем пост с правильными параметрами
        $post = new Post(
            ids: $ids,
            text: $text,
            videos: $videos,
            links: [], // Facebook API может предоставлять ссылки, но пока не обрабатываем
            photos: $photos,
            author: null, // Можно получить через дополнительный запрос
        );

        $this->logger->debug('Converted Facebook post', [
            'post_id' => $postId,
            'has_text' => !empty($text),
            'photos_count' => count($photos),
            'videos_count' => count($videos)
        ]);

        return $post;
    }

    /**
     * Выполняет запрос к Facebook Graph API
     */
    private function makeApiRequest(string $url, array $params): array
    {
        $fullUrl = $url . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->requestTimeoutSec,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'VK2TG-Facebook-Retriever/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("CURL Error: {$curlError}");
        }

        // Process images
            throw new \Exception("Failed to get response from Facebook API");
        }

        $data = json_decode($response, true);
        // Process attachments
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }

        if ($httpCode !== 200) {
                // Video processing can be added if needed
            $errorCode = $data['error']['code'] ?? $httpCode;
            throw new FacebookApiException($errorMessage, (int)$errorCode, $data);
        }
        // Remove duplicate photos
        return $data;
    }
