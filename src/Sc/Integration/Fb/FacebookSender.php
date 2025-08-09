<?php

declare(strict_types=1);

namespace Sc\Integration\Fb;

use Psr\Log\LoggerInterface;
use Sc\Integration\SenderInterface;
use Sc\Dto\TransferPostDto;
use Sc\Model\PostId;
use Sc\Model\PostIdCollection;
use Sc\Service\SuccessHook;

readonly class FacebookSender implements SenderInterface
{
    private const FB_GRAPH_API_URL = 'https://graph.facebook.com/v18.0';
    private const MAX_RETRY_ATTEMPTS = 3;
    private const BASE_DELAY_SECONDS = 5;
    private const MAX_MESSAGE_LENGTH = 63206; // Facebook post limit

    private FacebookMessageFormatter $messageFormatter;

    public function __construct(
        private string          $accessToken,
        private string          $pageId,
        private LoggerInterface $logger,
        private SuccessHook     $successHook,
        private string          $systemName,
        private int             $requestTimeoutSec = 30,
    )
    {
        $this->messageFormatter = new FacebookMessageFormatter();
    }

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function supportsPolls(): bool
    {
        return false;
    }

    public function sendPost(TransferPostDto $transferPost): void
    {
        $formattedText = $this->messageFormatter->formatMessage($transferPost->post);

        if ($this->messageFormatter->shouldSendAsPhoto($transferPost->post->photos, $formattedText)) {
            try {
                $this->sendPhoto($transferPost, $formattedText);
                return;
            } catch (\Exception $e) {
                $this->handleSendError($transferPost->post->ids, $e);
            }
        }

        $this->sendTextPost($transferPost, $formattedText);
    }

    private function sendPhoto(TransferPostDto $transferPost, string $formattedText): void
    {
        $photoUrl = $transferPost->post->hasPhoto() ? $transferPost->post->photos[0] : null;
        if (null === $photoUrl) {
            throw new \InvalidArgumentException('No photo URL available');
        }

        $response = $this->sendWithRetry(function() use ($photoUrl, $formattedText) {
            return $this->makeApiRequest('photos', [
                'url' => $photoUrl,
                'message' => $formattedText,
                'published' => true
            ]);
        }, ['photo' => $photoUrl, 'message' => $formattedText]);

        if ($response && isset($response['id'])) {
            $newPostId = new PostId($response['id'], $transferPost->otherSystemName);
            $transferPost->post->ids->add($newPostId);
            $this->successHook->handleSuccessfulSend($transferPost, $formattedText);
        }
    }

    private function sendTextPost(TransferPostDto $transferPost, string $formattedText): void
    {
        if (empty($formattedText)) {
            return;
        }

        if (strlen($formattedText) > self::MAX_MESSAGE_LENGTH) {
            $this->logger->warning('Facebook post exceeds character limit, skipping', [
                'length' => strlen($formattedText),
                'limit' => self::MAX_MESSAGE_LENGTH,
                'post_id' => $transferPost->post->id
            ]);
            return;
        }

        $response = $this->sendWithRetry(function() use ($formattedText) {
            return $this->makeApiRequest('feed', [
                'message' => $formattedText,
                'published' => true
            ]);
        }, ['message' => $formattedText]);

        if ($response && isset($response['id'])) {
            $transferPost->post->ids->add(
                new PostId($response['id'], $transferPost->otherSystemName)
            );
            $this->successHook->handleSuccessfulSend($transferPost, $formattedText);
        }
    }

    private function sendWithRetry(callable $sendFunction, array $context = []): ?array
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            try {
                return $sendFunction();
            } catch (FacebookApiException $e) {
                $attempt++;

                // Check if this is a rate limit error
                if ($this->isRateLimitError($e)) {
                    $delay = self::BASE_DELAY_SECONDS * $attempt;

                    if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                        $this->logger->warning('Facebook rate limit reached, waiting {delay} seconds before retry {attempt}/{max}', [
                            'delay' => $delay,
                            'attempt' => $attempt,
                            'max' => self::MAX_RETRY_ATTEMPTS,
                            'context' => $context
                        ]);

                        sleep($delay);
                        continue;
                    }
                }

                $this->handleSendError(new PostIdCollection([]), $e, $context);
                return null;
            } catch (\Throwable $e) {
                $this->handleSendError(new PostIdCollection([]), $e, $context);
                return null;
            }
        }

        return null;
    }

    private function makeApiRequest(string $endpoint, array $params): array
    {
        $url = self::FB_GRAPH_API_URL . "/{$this->pageId}/{$endpoint}";

        $postData = array_merge($params, [
            'access_token' => $this->accessToken
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->requestTimeoutSec,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'VK2TG-Facebook-Sender/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("CURL Error: {$curlError}");
        }

        if ($response === false) {
            throw new \Exception("Failed to get response from Facebook API");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }

        if ($httpCode !== 200) {
            $errorMessage = $data['error']['message'] ?? 'Unknown Facebook API error';
            $errorCode = $data['error']['code'] ?? $httpCode;
            throw new FacebookApiException($errorMessage, (int)$errorCode, $data);
        }

        return $data;
    }

    private function isRateLimitError(FacebookApiException $e): bool
    {
        return in_array($e->getCode(), [4, 17, 341, 368]) ||
               str_contains($e->getMessage(), 'rate limit') ||
               str_contains($e->getMessage(), 'too many requests');
    }

    private function handleSendError(PostIdCollection $collection, \Throwable $e, array $context = []): void
    {
        $this->logger->error('send Facebook ' . (isset($context['photo']) ? 'Photo' : 'Post'), array_merge($context, [
            'id' => (string)$collection,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));
    }
}
