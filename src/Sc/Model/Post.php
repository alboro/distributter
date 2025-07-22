<?php

declare(strict_types=1);

namespace Sc\Model;

/**
 * Абстрактная модель поста с данными для синхронизации
 */
readonly class Post
{
    public function __construct(
        public PostIdCollection $ids,
        public string           $text,
        public array            $videos,
        public array            $links,
        public array            $photos,
        public ?string          $author,
        public ?Poll            $poll = null,
    )
    {
    }

    public function hasPhoto(): bool
    {
        return isset($this->photos[0]) && $this->photos[0] !== null;
    }

    public function hasPoll(): bool
    {
        return $this->poll !== null;
    }
}