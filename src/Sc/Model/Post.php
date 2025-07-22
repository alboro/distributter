<?php

declare(strict_types=1);

namespace Sc\Model;

/**
 * Abstract post model with data for synchronization
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