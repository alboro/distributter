<?php

declare(strict_types=1);

namespace Sc\Model;

/**
 * Модель опроса для синхронизации между социальными сетями
 */
readonly class Poll
{
    public function __construct(
        public string $question,
        public array $options,
        public ?int $totalVotes = null,
        public bool $isAnonymous = true,
        public bool $isMultipleChoice = false,
        public ?int $originalId = null,
        public ?int $endDate = null,
    ) {
    }

    /**
     * Проверяет, есть ли варианты ответов
     */
    public function hasOptions(): bool
    {
        return !empty($this->options);
    }

    /**
     * Получает количество вариантов ответов
     */
    public function getOptionsCount(): int
    {
        return count($this->options);
    }

    /**
     * Проверяет, истек ли срок опроса
     */
    public function isExpired(): bool
    {
        return $this->endDate !== null && $this->endDate < time();
    }

    /**
     * Получает текстовое представление опроса
     */
    public function getFormattedText(): string
    {
        $text = "📊 {$this->question}\n\n";

        foreach ($this->options as $index => $option) {
            $text .= sprintf("%d. %s", $index + 1, $option['text']);
            if (isset($option['votes']) && $this->totalVotes > 0) {
                $percentage = round(($option['votes'] / $this->totalVotes) * 100, 1);
                $text .= sprintf(" (%d голосов, %s%%)", $option['votes'], $percentage);
            }
            $text .= "\n";
        }

        if ($this->totalVotes !== null) {
            $text .= "\n👥 Всего голосов: {$this->totalVotes}";
        }

        if ($this->isExpired()) {
            $text .= "\n⏰ Опрос завершен";
        }

        return $text;
    }
}
