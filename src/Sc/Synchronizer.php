<?php

declare(strict_types=1);

namespace Sc;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use TelegramBot\Api\BotApi;
use VK\Client\VKApiClient;
use Sc\Config\AppConfig;
use Sc\Filter\{PostFilter, PostFilterException};
use Sc\Parser\VkAttachmentParser;
use Sc\Service\{AuthorService, MessageFormatter, TelegramSender};

final readonly class Synchronizer
{
    private AppConfig $config;
    private VKApiClient $vk;
    private Storage $storage;
    private LoggerInterface $logger;
    private PostFilter $postFilter;
    private VkAttachmentParser $attachmentParser;
    private AuthorService $authorService;
    private TelegramSender $telegramSender;

    public function __construct()
    {
        $this->config = AppConfig::fromEnvironment();
        $this->storage = Storage::load();
        $this->vk = new VKApiClient();

        $this->logger = $this->createLogger();
        $this->initializeServices();
    }

    public function logger(): \Psr\Log\LoggerInterface
    {
        return $this->logger;
    }

    public function invoke(): void
    {
        $this->logger->debug('Request posts');

        $vkData = $this->fetchVkPosts();
        if (!$this->validateVkResponse($vkData)) {
            return;
        }

        $this->processVkPosts($vkData['items']);
        $this->finalize();
    }

    public function pin(): void
    {
        $vkData = $this->vk->wall()->get($this->config->vkToken, [
            'owner_id' => $this->config->vkGroupId,
            'count' => 1,
        ]);

        $attempts = 0;
        do {
            sleep(4);
            $randomPost = $this->getRandomPost($vkData['count']);
            $randomPostId = (int) $randomPost['items'][0]['id'];

            echo sprintf('[%s]', $randomPostId);

            if ($this->canPinPost($randomPostId, $randomPost['items'][0])) {
                $this->pinPost($randomPostId);
                break;
            }
        } while (100 === ++$attempts);

        sleep(4);
    }

    private function createLogger(): \Psr\Log\LoggerInterface
    {
        $logger = new Logger('vk2tg');
        $logger->pushHandler(new StreamHandler(STDOUT, Logger::DEBUG));
        $logger->pushHandler(new StreamHandler(__DIR__ . '/../../log.log', Logger::ERROR));
        return $logger;
    }

    private function initializeServices(): void
    {
        // Инициализируем Telegram Bot
        $tgBot = new BotApi($this->config->tgBotToken);
        if ($this->config->tgProxyDSN !== null) {
            $tgBot->setProxy($this->config->tgProxyDSN);
        }

        // Создаем все сервисы используя object initializer pattern
        $this->postFilter = new PostFilter(
            logger: $this->logger,
            storage: $this->storage,
            vkGroupId: $this->config->vkGroupId,
            ignoreTag: $this->config->ignoreTag
        );

        $this->attachmentParser = new VkAttachmentParser(
            requestTimeoutSec: $this->config->requestTimeoutSec
        );

        $this->authorService = new AuthorService(
            vk: $this->vk,
            vkToken: $this->config->vkToken,
            logger: $this->logger
        );

        // MessageFormatter теперь создается внутри TelegramSender
        $this->telegramSender = new TelegramSender(
            tgBot: $tgBot,
            channelId: $this->config->tgChannelId,
            useTgApi: $this->config->useTgApi,
            enableNotification: $this->config->enableNotification,
            logger: $this->logger,
            storage: $this->storage,
            messageFormatter: new MessageFormatter()
        );
    }

    private function fetchVkPosts(): array
    {
        return $this->vk->wall()->get($this->config->vkToken, [
            'owner_id' => $this->config->vkGroupId,
            'offset' => 0,
            'count' => $this->config->itemCount,
        ]);
    }

    private function validateVkResponse(array $vkData): bool
    {
        if (!isset($vkData['items'])) {
            $fileName = sprintf('empty_response_%d.txt', time());
            $this->logger->error('empty response', ['file' => $fileName]);
            file_put_contents($fileName, json_encode($vkData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return false;
        }
        return true;
    }

    private function processVkPosts(array $vkItems): void
    {
        // Обрабатываем посты в обратном порядке (от старых к новым)
        array_map(
            fn(array $vkItem) => $this->safeProcessPost($vkItem),
            array_reverse($vkItems)
        );
    }

    private function safeProcessPost(array $vkItem): void
    {
        try {
            $this->processPost($vkItem);
            sleep(1);
        } catch (PostFilterException) {
            // Пропускаем пост (это ожидаемое поведение для фильтрации)
        } catch (\Throwable $e) {
            $this->logger->error(get_class($e) . ': ' . $e->getMessage(), [
                'post_id' => $vkItem['id'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function processPost(array $vkItem): void
    {
        $this->postFilter->validatePost($vkItem);
        $this->attachmentParser->validatePoll($vkItem);

        $vkItemId = (int)$vkItem['id'];
        $text = $vkItem['text'];
        $author = $this->authorService->getAuthorFromPost($vkItem);

        // Парсим вложения используя деструктурирование
        [$videos, $links, $photos] = [
            $this->attachmentParser->parseVideos($vkItem),
            $this->attachmentParser->parseLinks($vkItem),
            $this->attachmentParser->parsePhotos($vkItem)
        ];

        // Отправляем в Telegram (форматирование происходит внутри TelegramSender)
        $this->telegramSender->sendPost($vkItemId, $text, $videos, $links, $photos, $author);
    }

    private function finalize(): void
    {
        $this->storage->save();

        $this->logger->debug('Done.');
    }

    private function getRandomPost(int $totalCount): array
    {
        return $this->vk->wall()->get($this->config->vkToken, [
            'owner_id' => $this->config->vkGroupId,
            'offset' => random_int(0, $totalCount - 1),
            'count' => 1,
        ]);
    }

    private function canPinPost(int $postId, array $post): bool
    {
        return $this->storage->hasId($postId)
            && !in_array($postId, $this->postFilter->getExcludeIds(), true)
            && empty($post['copy_history']);
    }

    private function pinPost(int $postId): void
    {
        $this->vk->wall()->pin($this->config->vkToken, [
            'owner_id' => $this->config->vkGroupId,
            'post_id' => $postId,
        ]);
    }
}
