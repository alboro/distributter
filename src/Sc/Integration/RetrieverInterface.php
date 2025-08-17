<?php

declare(strict_types=1);

namespace Sc\Integration;


use Sc\Model\Post;

/**
 * Extracts and processes posts from API
 */
interface RetrieverInterface
{
    /**
     * Returns the name of the system served by the retriever
     */
    public function systemName(): string;

    /**
     * Gets posts from the system
     *
     * @return Post[]
     */
    public function retrievePosts(): array;

    public function channelId(): string;
}