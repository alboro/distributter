<?php

declare(strict_types=1);

namespace Sc;

/**
 * Класс для хранения связей между VK постами и TG сообщениями
 * Поддерживает новый формат v3: {"vk": "12345", "tg": "67,68,69"}
 */
class Storage implements \JsonSerializable
{
    private LimitedList $lastIds;

    public function __construct()
    {
        $this->lastIds = new LimitedList(2000000);
    }

    /**
     * Добавляет связь VK пост -> TG сообщение
     */
    public function addId(int $vkId, int $tgId): void
    {
        $this->lastIds->addMapping((string)$vkId, (string)$tgId);
    }

    /**
     * Проверяет, есть ли VK пост в хранилище
     */
    public function hasId(int $vkId): bool
    {
        return $this->lastIds->hasVkPost((string)$vkId);
    }

    /**
     * Получает все TG ID для VK поста
     */
    public function getTgIds(int $vkId): array
    {
        return array_map('intval', $this->lastIds->getTgIds((string)$vkId));
    }

    /**
     * Сохраняет данные в файл
     */
    public function save(): void
    {
        $data = $this->jsonSerialize();

        // Принудительно делаем VK ID строками в JSON
        $jsonOutput = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // $jsonOutput = preg_replace('/("vk":\s*)(\d+)/', '$1"$2"', $jsonOutput);

        file_put_contents(__DIR__ . '/../../storage.v3.json', $jsonOutput);
    }

    /**
     * Сериализация для JSON в формате v3
     */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->lastIds
        ];
    }

    /**
     * Загружает данные из файла
     */
    public static function load(): Storage
    {
        $storage = new self();

        // Пытаемся загрузить новый формат v3
        $v3Path = __DIR__ . '/../../storage.v3.json';
        if (file_exists($v3Path)) {
            $storage->loadFromV3File($v3Path);
            return $storage;
        }

        // Fallback к старому формату v2
        $v2Path = __DIR__ . '/../../new_storage.json';
        if (file_exists($v2Path)) {
            $storage->loadFromV2File($v2Path);
            return $storage;
        }

        return $storage;
    }

    /**
     * Загружает данные из файла формата v3
     */
    private function loadFromV3File(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (!$data || !is_array($data)) {
            return;
        }

        if (isset($data['items']) && is_array($data['items'])) {
            $this->lastIds->loadFromArray($data['items']);
        }
    }

    /**
     * Загружает данные из файла формата v2 (старый new_storage.json)
     */
    private function loadFromV2File(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (!$data || !is_array($data) || !isset($data['items'])) {
            return;
        }

        // Конвертируем из старого формата
        foreach ($data['items'] as $item) {
            if (is_array($item) && count($item) === 1) {
                $vkId = (string)array_key_first($item);
                $tgId = (string)$item[$vkId];
                $this->lastIds->addMapping($vkId, $tgId);
            }
        }
    }

    /**
     * Возвращает статистику хранилища
     */
    public function getStats(): array
    {
        $items = $this->lastIds->getItems();
        $totalTgMessages = 0;
        $multiMessagePosts = 0;

        foreach ($items as $item) {
            $tgCount = count(explode(',', $item['tg']));
            $totalTgMessages += $tgCount;
            if ($tgCount > 1) {
                $multiMessagePosts++;
            }
        }

        return [
            'vk_posts' => count($items),
            'tg_messages' => $totalTgMessages,
            'multi_message_posts' => $multiMessagePosts,
        ];
    }
}