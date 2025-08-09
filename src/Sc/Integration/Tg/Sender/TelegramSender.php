<?php

declare(strict_types=1);

namespace Sc\Integration\Tg\Sender;

use Psr\Log\LoggerInterface;
use Sc\Integration\SenderInterface;
use Sc\Dto\TransferPostDto;
use Sc\Model\PostId;
use Sc\Model\PostIdCollection;
use Sc\Service\MessageSplitter;
use Sc\Service\SuccessHook;
use Telegram\Bot\Api as TelegramApi;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;

readonly class TelegramSender implements SenderInterface
{
    private const string CAPTION_TOO_LONG_ERROR = 'Bad Request: MEDIA_CAPTION_TOO_LONG';
    private const int MAX_RETRY_ATTEMPTS = 3;
    private const int BASE_DELAY_SECONDS = 5;
    private const int MAX_POLL_OPTIONS = 10;
    private const int MAX_POLL_QUESTION_LENGTH = 300;
    private TelegramMessageFormatter $messageFormatter;

    public function __construct(
        private TelegramApi     $tgBot,
        private string          $channelId,
        private bool            $enableNotification,
        private LoggerInterface $logger,
        private SuccessHook     $successHook,
        private string          $systemName,
        private MessageSplitter $messageSplitter,
    )
    {
        $this->messageFormatter = new TelegramMessageFormatter();
    }

    public function systemName(): string
    {
        return $this->systemName;
    }

    public function supportsPolls(): bool
    {
        return true; // Telegram supports polls
    }

    /**
     * Sends post to Telegram, automatically choosing optimal format
     */
    public function sendPost(TransferPostDto $transferPost): void
    {
        $formattedForTgText = $this->messageFormatter->formatMessage($transferPost->post);

        // Check if there's a poll in the post
        if ($transferPost->post->hasPoll()) {
            try {
                $this->sendPoll($transferPost, $formattedForTgText);
                return;
            } catch (\Exception $e) {
                $this->logger->warning('Failed to send poll, falling back to text message', [
                    'error' => $e->getMessage(),
                    'post_id' => (string) $transferPost->post->ids
                ]);
            }
        }

        if ($this->messageFormatter->shouldSendAsPhoto($transferPost->post->photos, $formattedForTgText)) {
            try {
                $this->sendPhoto($transferPost, $formattedForTgText);
                return;
            } catch (\Exception $e) {
                $this->handleSendError($transferPost->post->ids, $e);
            }
        }
        $this->sendMessage($transferPost, $formattedForTgText);
    }

    private function sendPhoto(TransferPostDto $transferPost, string $formattedForTgText): void
    {
        $photoUrl = $transferPost->post->hasPhoto() ? $transferPost->post->photos[0] : null;
        if (null === $photoUrl) {
            throw new \InvalidArgumentException('No photo URL available');
        }

        $message = $this->sendWithRetry(function() use ($photoUrl, $formattedForTgText) {
            return $this->tgBot->sendPhoto([
                'chat_id' => $this->channelId,
                'photo' => InputFile::create($photoUrl),
                'caption' => $formattedForTgText,
                'disable_notification' => !$this->enableNotification,
                'parse_mode' => 'HTML'
            ]);
        }, ['photo' => $photoUrl, 'caption' => $formattedForTgText]);

        if ($message) {
            $newPostId = new PostId((string)$message->message_id, $transferPost->otherSystemName);
            $transferPost->post->ids->add($newPostId);
            $this->successHook->handleSuccessfulSend($transferPost, $formattedForTgText);
        }
    }

    private function sendMessage(TransferPostDto $transferPost, string $formattedForTgText): void
    {
        if (empty($formattedForTgText)) {
            return;
        }

        // Split long messages into parts while preserving HTML structure
        $messageParts = $this->messageSplitter->splitMessageSafely($formattedForTgText);
        $channelId = $this->channelId;
        $enableNotification = $this->enableNotification;
        foreach ($messageParts as $index => $part) {
            $message = $this->sendWithRetry(function() use ($part, $channelId, $enableNotification) {
                return $this->tgBot->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $part,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => false,
                    'disable_notification' => !$enableNotification
                ]);
            }, ['text' => $part, 'part' => $index + 1]);

            if ($message) {
                $transferPost->post->ids->add(
                    new PostId((string)$message->message_id, $transferPost->otherSystemName)
                );
                $this->successHook->handleSuccessfulSend($transferPost, $part, $index, count($messageParts));
            }
        }
    }

    private function sendPoll(TransferPostDto $transferPost, string $formattedForTgText): void
    {
        $poll = $transferPost->post->poll;
        if (!$poll) {
            throw new \InvalidArgumentException('No poll available');
        }

        // Check the number of answer options
        if (count($poll->options) > self::MAX_POLL_OPTIONS) {
            $this->logger->warning('Poll has too many options for Telegram, skipping post', [
                'options_count' => count($poll->options),
                'max_allowed' => self::MAX_POLL_OPTIONS,
                'post_id' => (string) $transferPost->post->ids
            ]);
            return; // Skip this post
        }

        // Extract answer options
        $options = [];
        foreach ($poll->options as $option) {
            $options[] = $option['text'];
        }

        if (count($options) < 2) {
            throw new \InvalidArgumentException('Poll must have at least 2 options');
        }

        // Formulate the poll question
        $question = $poll->question;

        // Check the length of the question - if it exceeds the limit, skip the post
        if (strlen($question) > self::MAX_POLL_QUESTION_LENGTH) {
            $this->logger->warning('Poll question too long for Telegram, skipping post', [
                'question_length' => strlen($question),
                'max_allowed' => self::MAX_POLL_QUESTION_LENGTH,
                'post_id' => (string) $transferPost->post->ids
            ]);
            return; // Skip this post
        }

        $channelId = $this->channelId;
        $enableNotification = $this->enableNotification;

        $this->sendMessage($transferPost, $formattedForTgText);

        $message = $this->sendWithRetry(function() use ($question, $options, $poll, $channelId, $enableNotification) {
            return $this->tgBot->sendPoll([
                'chat_id' => $channelId,
                'question' => $question,
                'options' => $options,
                'is_anonymous' => true, // Telegram channels only support anonymous polls
                'allows_multiple_answers' => $poll->isMultipleChoice,
                'disable_notification' => !$enableNotification,
            ]);
        }, ['question' => $question, 'options' => $options]);

        if ($message) {
            $newPostId = new PostId((string)$message->message_id, $transferPost->otherSystemName);
            $transferPost->post->ids->add($newPostId);
            $this->successHook->handleSuccessfulSend($transferPost, $question);
        }
    }

    /**
     * Sends a request with retries when rate limits are exceeded
     */
    private function sendWithRetry(callable $sendFunction, array $context = []): ?Message
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            try {
                return $sendFunction();
            } catch (TelegramResponseException $e) {
                $attempt++;

                // Check if this is a rate limit error
                if ($this->isRateLimitError($e)) {
                    $retryAfter = $this->extractRetryAfter($e->getMessage());
                    $delay = $retryAfter ?: (self::BASE_DELAY_SECONDS * $attempt);

                    if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                        $this->logger->warning('Rate limit reached, waiting {delay} seconds before retry {attempt}/{max}', [
                            'delay' => $delay,
                            'attempt' => $attempt,
                            'max' => self::MAX_RETRY_ATTEMPTS,
                            'context' => $context
                        ]);

                        sleep($delay);
                        continue;
                    }
                }

                // For other errors or the last attempt
                $this->handleSendError(new PostIdCollection([]), $e, $context);

                // If this is a long text error, rethrow it
                if ($e->getMessage() === self::CAPTION_TOO_LONG_ERROR) {
                    throw $e;
                }

                return null;
            } catch (\Throwable $e) {
                $this->handleSendError(new PostIdCollection([]), $e, $context);
                return null;
            }
        }

        return null;
    }

    /**
     * Checks if the error is a rate limit exceeded error
     */
    private function isRateLimitError(TelegramResponseException $e): bool
    {
        return str_contains($e->getMessage(), 'Too Many Requests');
    }

    /**
     * Extracts the retry time from the error message
     */
    private function extractRetryAfter(string $errorMessage): ?int
    {
        if (preg_match('/retry after (\d+)/', $errorMessage, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    private function handleSendError(PostIdCollection $collection, \Throwable $e, array $context = []): void
    {
        echo $e->getMessage() . PHP_EOL;

        $this->logger->error('send ' . (isset($context['photo']) ? 'Photo' : 'Message'), array_merge($context, [
            'id' => (string)$collection,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));
    }
}
