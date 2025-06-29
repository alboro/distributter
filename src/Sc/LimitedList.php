<?php

declare(strict_types=1);

namespace Sc;

/**
 * Ограниченный список для хранения связей VK -> TG сообщений
 * Поддерживает новый формат v3: {"vk": "12345", "tg": "67,68,69"}
 */
class LimitedList implements \JsonSerializable
{
    private array $items = [];
    private int $maxSize;

    public function __construct(int $maxSize = 2000000)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * Добавляет связь VK пост -> TG сообщение
     */
    public function addMapping(string $vkId, string $tgId): void
    {
        // Ищем существующую запись для этого VK поста
        $existingIndex = $this->findVkPost($vkId);

        if ($existingIndex !== null) {
            // Добавляем TG ID к существующему посту
            $tgIds = explode(',', $this->items[$existingIndex]['tg']);
            if (!in_array($tgId, $tgIds, true)) {
                $tgIds[] = $tgId;
                $this->items[$existingIndex]['tg'] = implode(',', $tgIds);
            }
        } else {
            // Создаем новую запись
            $this->items[] = [
                'vk' => $vkId,
                'tg' => $tgId
            ];

            // Ограничиваем размер списка
            if (count($this->items) > $this->maxSize) {
                array_shift($this->items);
            }
        }
    }

    /**
     * Проверяет, есть ли VK пост в списке
     */
    public function hasVkPost(string $vkId): bool
    {
        return $this->findVkPost($vkId) !== null;
    }

    /**
     * Получает все TG ID для VK поста
     */
    public function getTgIds(string $vkId): array
    {
        $index = $this->findVkPost($vkId);
        if ($index === null) {
            return [];
        }

        return explode(',', $this->items[$index]['tg']);
    }

    /**
     * Загружает данные из массива (для миграции и загрузки из файла)
     */
    public function loadFromArray(array $items): void
    {
        $this->items = [];

        foreach ($items as $item) {
            if (isset($item['vk']) && isset($item['tg'])) {
                $this->items[] = [
                    'vk' => (string)$item['vk'],
                    'tg' => (string)$item['tg']
                ];
            }
        }

        // Ограничиваем размер при загрузке
        if (count($this->items) > $this->maxSize) {
            $this->items = array_slice($this->items, -$this->maxSize);
        }
    }

    /**
     * Находит индекс VK поста в списке
     */
    private function findVkPost(string $vkId): ?int
    {
        foreach ($this->items as $index => $item) {
            if ($item['vk'] === $vkId) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Возвращает все записи
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Возвращает количество записей
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Сериализация для JSON
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }

    // Методы для обратной совместимости со старым API

    /**
     * @deprecated Используйте addMapping() вместо push()
     */
    public function push(array $item): void
    {
        if (count($item) === 1) {
            $vkId = (string)array_key_first($item);
            $tgId = (string)$item[$vkId];
            $this->addMapping($vkId, $tgId);
        }
    }

    /**
     * @deprecated Используйте hasVkPost() вместо has()
     */
    public function has($vkId): bool
    {
        return $this->hasVkPost((string)$vkId);
    }

    /**
     * @deprecated Используйте loadFromArray() вместо pushAll()
     */
    public function pushAll(array $items): void
    {
        foreach ($items as $item) {
            if (is_array($item) && count($item) === 1) {
                $this->push($item);
            }
        }
    }
}