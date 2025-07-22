<?php

declare(strict_types=1);

namespace Sc\Model;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Коллекция для работы с множественными PostId
 */
final class PostIdCollection implements IteratorAggregate, Countable
{
    /** @var PostId[] */
    private array $items;

    /**
     * @param PostId[] $items
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    /**
     * Добавить PostId в коллекцию
     */
    public function add(PostId $postId): self
    {
        $this->items[] = $postId;

        return $this;
    }

    /**
     * Проверить содержит ли коллекция указанный PostId
     */
    public function contains(PostId $postId): bool
    {
        foreach ($this->items as $item) {
            if ($item->equals($postId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Фильтровать коллекцию по системе
     */
    public function filterBySystem(string $systemName): self
    {
        $items = array_filter(
            $this->items,
            static fn(PostId $postId) => $postId->systemName === $systemName
        );

        return new self($items);
    }

    /**
     * Получить все уникальные системы в коллекции
     *
     * @return string[]
     */
    public function getSystems(): array
    {
        $systems = [];
        foreach ($this->items as $item) {
            $systems[$item->systemName] = true;
        }

        return array_keys($systems);
    }

    /**
     * Объединить с другой коллекцией
     */
    public function merge(PostIdCollection $other): self
    {
        $items = $this->items;
        foreach ($other->items as $item) {
            if (!$this->contains($item)) {
                $items[] = $item;
            }
        }

        return new self($items);
    }

    /**
     * Получить массив строковых представлений
     *
     * @return string[]
     */
    public function toStringArray(): array
    {
        return array_map(
            static fn(PostId $postId) => $postId->toString(),
            $this->items
        );
    }

    public function __toString(): string
    {
        return implode(',', $this->toStringArray());
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Получить первый элемент или null если коллекция пуста
     */
    public function first(): ?PostId
    {
        return $this->items[0] ?? null;
    }

    /**
     * Получить последний элемент или null если коллекция пуста
     */
    public function last(): ?PostId
    {
        $count = count($this->items);
        return $count > 0 ? $this->items[$count - 1] : null;
    }

    /**
     * Получить элемент по индексу
     */
    public function get(int $index): ?PostId
    {
        return $this->items[$index] ?? null;
    }

    /**
     * Получить все элементы как массив
     *
     * @return PostId[]
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
