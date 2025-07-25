<?php

namespace Sc\Channels;

use Sc\Dto\TransferPostDto;

interface SenderInterface
{
    public function systemName(): string;

    /**
     * Отправляет пост в Telegram, автоматически выбирая оптимальный формат
     */
    public function sendPost(TransferPostDto $transferPost): void;

    /**
     * Проверяет, поддерживает ли данный отправщик опросы
     */
    public function supportsPolls(): bool;
}