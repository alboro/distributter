<?php

declare(strict_types=1);

namespace Sc\Service;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Sc\Channels\RetrieverInterface;
use Sc\Channels\SenderInterface;
use Sc\Channels\Tg\Retriever\TelegramRetriever;
use Sc\Channels\Tg\Sender\TelegramSender;
use Sc\Channels\Vk\Retriever\AuthorService;
use Sc\Channels\Vk\Retriever\VkAttachmentParser;
use Sc\Channels\Vk\Retriever\VkRetriever;
use Sc\Channels\Vk\Sender\VkSender;
use Sc\Config\AppConfig;
use Sc\Dto\TransferPostDto;
use Sc\Filter\{PostFilter, PostFilterException, SameSystemPostFilterException};
use Sc\Model\Post;
use Telegram\Bot\Api as TelegramApi;
use VK\Client\VKApiClient;

final readonly class Synchronizer
{
    private Repository $storage;
    private LoggerInterface $logger;
    private PostFilter $postFilter;

    /** @var RetrieverInterface[] */
    private array $retrievers;

    /** @var SenderInterface[] */
    private array $senders;

    public function __construct(private AppConfig $config)
    {
        $this->storage = Repository::load($this->config->storageFilePath);

        $this->logger = $this->createLogger();
        // Create filter (only to check already processed posts)
        $this->postFilter = new PostFilter();
        $this->initializeServices();
    }

    private function createLogger(): LoggerInterface
    {
        $logger = new Logger('distr');

        // Console handler - shows all messages including errors
        $consoleHandler = new StreamHandler(STDOUT, Logger::DEBUG);
        $logger->pushHandler($consoleHandler);

        // File handler - only errors and critical messages
        $fileHandler = new StreamHandler($this->config->logFilePath, Logger::ERROR);
        $logger->pushHandler($fileHandler);

        return $logger;
    }

    private function initializeServices(): void
    {
        $telegramSender = $this->tgSenderFactory();
        $telegramRetriever = $this->tgRetrieverFactory();
        $vkRetriever = $this->vkRetrieverFactory();
        $vkSender = $this->vkSenderFactory();

        $this->retrievers = array_filter([$vkRetriever, $telegramRetriever]);
        if ($this->config->mockSenders) {
            $this->senders = [];
        } else {
            $this->senders = array_filter([$telegramSender, $vkSender]);
        }
    }

    public function invoke(): void
    {
        $this->logger->debug(
            'Starting synchronization',
            [
                'retrievers' => array_map(fn(RetrieverInterface $retriever): string => $retriever->systemName(), $this->retrievers),
                'senders' => array_map(fn(SenderInterface $sender): string => $sender->systemName(), $this->senders),
            ]
        );
        foreach ($this->retrievers as $retriever) {
            try {
                $this->logger->debug('Retrieve posts from', [
                    'system' => $retriever->systemName(),
                    'channel_id' => $retriever->channelId(),
                    'count' => $this->config->itemCount,
                ]);

                $posts = $retriever->retrievePosts();

                $this->logger->debug("Posts were retrieved", [
                    'count' => count($posts),
                    'from_system' => $retriever->systemName(),
                ]);

                $this->processPosts($posts, $retriever->systemName());
            } catch (\Throwable $e) {
                $this->logger->error('Error during retrieval', [
                    'system' => $retriever->systemName(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        $this->logger->debug('End synchronization');
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param Post[] $posts
     */
    private function processPosts(array $posts, string $fromSystemName): void
    {
        foreach ($posts as $post) {
            $this->safeProcessPost($post, $fromSystemName);
        }
    }

    private function safeProcessPost(Post $post, string $fromSystemName): void
    {
        foreach ($this->senders as $sender) {
            $transferPost = new TransferPostDto($post, $fromSystemName, $sender->systemName());
            try {
                $this->postFilter->validate($transferPost);
                $sender->sendPost($transferPost);
            } catch (SameSystemPostFilterException) {
            } catch (PostFilterException $exception) {
                $this->logger->debug($exception->getMessage() . ' - ' . $post->ids);
            } catch (\Throwable $e) {
                $this->logger->error(get_class($e) . ': ' . $e->getMessage(), [
                    'post_id' => (string) $post->ids,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    private function tgSenderFactory(): ?TelegramSender
    {
        if (null === $this->config->tgSenderConfig) {
            return null;
        }
        // Initialize Telegram Bot using irazasyed/telegram-bot-sdk
        $tgBot = new TelegramApi($this->config->tgSenderConfig->botToken);

        // Create MessageSplitter for splitting long messages
        $messageSplitter = new MessageSplitter(4000); // Telegram limit with margin

        // Create alternative Telegram sender
        return new TelegramSender(
            tgBot: $tgBot,
            channelId: $this->config->tgSenderConfig->channelId ?? '',
            enableNotification: $this->config->tgSenderConfig->enableNotification,
            logger: $this->logger,
            successHook: new SuccessHook($this->logger, $this->storage),
            systemName: 'tg',
            messageSplitter: $messageSplitter,
        );
    }

    private function vkRetrieverFactory(): ?VkRetriever
    {
        if (null === $this->config->vkRetrieverConfig) {
            return null;
        }
        $vkApiClient = new VKApiClient();
        $attachmentParser = new VkAttachmentParser($this->config->requestTimeoutSec);

        // Using token from VkRetrieverConfig or fallback to empty string
        $token = $this->config->vkRetrieverConfig?->token ?? '';
        $authorService = new AuthorService($vkApiClient, $token, $this->logger);

        // Creating VK retriever
        return new VkRetriever(
            vk: $vkApiClient,
            config: $this->config->vkRetrieverConfig,
            logger: $this->logger,
            attachmentParser: $attachmentParser,
            authorService: $authorService,
            storage: $this->storage,
            systemName: 'vk',
        );
    }

    private function vkSenderFactory(): ?VkSender
    {
        return null === $this->config->vkSenderConfig ? null : new VkSender(
            vk: new VKApiClient(),
            config: $this->config->vkSenderConfig,
            logger: $this->logger,
            successHook: new SuccessHook($this->logger, $this->storage),
            cache: new StaticCache(),
            systemName: 'vk',
        );
    }

    private function tgRetrieverFactory(): ?TelegramRetriever
    {
        $telegramRetriever = null;
        if (!empty($this->config->tgRetrieverApiId) && !empty($this->config->tgRetrieverApiHash)) {
            try {
                $settings = new Settings();
                $settings->getAppInfo()->setApiId((int)$this->config->tgRetrieverApiId);
                $settings->getAppInfo()->setApiHash($this->config->tgRetrieverApiHash);

                // Disabling MadelineProto logging to avoid corrupting our logs
                $settings->getLogger()->setType(\danog\MadelineProto\Logger::ECHO_LOGGER);
                $settings->getLogger()->setLevel(\danog\MadelineProto\Logger::LEVEL_FATAL);

                // Settings for non-interactive mode
                $settings->getSerialization()->setInterval(3600); // Saving every hour

                // CRITICALLY IMPORTANT: Aggressive timeouts to prevent hanging
                $settings->getConnection()->setTimeout(10.0); // Connection timeout
                $settings->getRpc()->setRpcDropTimeout(15); // Timeout for RPC calls
                $settings->getRpc()->setFloodTimeout(10); // Timeout for flood control

                // Additional settings for stability
                $settings->getConnection()->setRetry(false); // Disabling automatic retries
                $settings->getConnection()->setPingInterval(30); // Increasing ping interval

                // Checking if the session file exists
                if (!file_exists($this->config->tgRetrieverSessionFile)) {
                    $this->logger->error('Telegram session file not found. Run: php bin/auth-telegram.php', [
                        'session_file' => $this->config->tgRetrieverSessionFile
                    ]);
                    throw new \Exception('Telegram session required');
                }

                // Saving current error handler
                $currentErrorHandler = set_error_handler(null);

                $madelineProto = new API($this->config->tgRetrieverSessionFile, $settings);

                // Attempting to start and verify authorization
                try {
                    $madelineProto->start();

                    // Checking authorization by calling getSelf()
                    $self = $madelineProto->getSelf();
                    if (empty($self)) {
                        throw new \Exception('Not authorized');
                    }

                    $this->logger->debug('Telegram authorization OK', [
                        'user_id' => $self['id'] ?? 'unknown'
                    ]);

                } catch (\Throwable $authError) {
                    $this->logger->error('Telegram authentication failed. Run: php bin/auth-telegram.php', [
                        'error' => $authError->getMessage()
                    ]);
                    throw new \Exception('Telegram authentication required: ' . $authError->getMessage());
                }

                // Restoring our error handler
                if ($currentErrorHandler !== null) {
                    set_error_handler($currentErrorHandler);
                }

                $telegramRetriever = new TelegramRetriever(
                    madelineProto: $madelineProto,
                    config: $this->config,
                    logger: $this->logger,
                    storage: $this->storage,
                    systemName: 'tg',
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to initialize Telegram retriever', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        return $telegramRetriever;
    }
}
