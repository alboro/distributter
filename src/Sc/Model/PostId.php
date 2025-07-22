<?php

declare(strict_types=1);

namespace Sc\Model;

/**
 * Value Object для ID поста с указанием системы источника
 */
readonly class PostId
{
    public function __construct(
        public string $id,
        public string $systemName
    ) {}

    public function toString(): string
    {
        return $this->systemName . ':' . $this->id;
    }

    public function equals(PostId $other): bool
    {
        return $this->id === $other->id && $this->systemName === $other->systemName;
    }
}
