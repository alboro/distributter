<?php

declare(strict_types=1);

namespace Sc\Model;

/**
 * Poll model for synchronization between social networks
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
     * Checks if there are answer options
     */
    public function hasOptions(): bool
    {
        return !empty($this->options);
    }

    /**
     * Gets the number of answer options
     */
    public function getOptionsCount(): int
    {
        return count($this->options);
    }

    /**
     * Checks if the poll has expired
     */
    public function isExpired(): bool
    {
        return $this->endDate !== null && $this->endDate < time();
    }

    /**
     * Gets the text representation of the poll
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
