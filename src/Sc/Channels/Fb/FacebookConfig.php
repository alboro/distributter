<?php

declare(strict_types=1);

namespace Sc\Channels\Fb;

/**
 * Конфигурация для работы с Facebook API
 */
readonly class FacebookConfig
{
    public function __construct(
        public string $pageAccessToken,
        public string $pageId,
        public bool $enableRetriever = false,
        public bool $enableSender = false,
        public int $requestTimeoutSec = 30,
    ) {}

    /**
     * Создает конфигурацию из переменных окружения
     */
    public static function fromEnvironment(): self
    {
        return new self(
            pageAccessToken: $_ENV['FB_PAGE_ACCESS_TOKEN'] ?? '',
            pageId: $_ENV['FB_PAGE_ID'] ?? '',
            requestTimeoutSec: (int)($_ENV['FB_REQUEST_TIMEOUT'] ?? 30),
        );
    }

    /**
     * Проверяет, можно ли использовать ретривер
     */
    public function canUseRetriever(): bool
    {
        return $this->enableRetriever &&
               !empty($this->pageAccessToken) &&
               !empty($this->pageId);
    }

    /**
     * Проверяет, можно ли использовать сендер
     */
    public function canUseSender(): bool
    {
        return $this->enableSender &&
               !empty($this->pageAccessToken) &&
               !empty($this->pageId);
    }

    /**
     * Возвращает URL для получения токенов (для документации)
     */
    public function getTokenUrl(): string
    {
        return 'https://developers.facebook.com/tools/explorer/';
    }
}
