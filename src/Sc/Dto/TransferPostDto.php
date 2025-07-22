<?php

namespace Sc\Dto;

use Sc\Model\Post;
use Sc\Model\PostId;
use Sc\Model\PostIdCollection;

class TransferPostDto
{
    public function __construct(
        public Post $post,
        public string $fromSystemName,
        public string $otherSystemName
    ) {
    }

    public function transferredPostIdCollection(): PostIdCollection
    {
        return $this->post->ids->filterBySystem($this->otherSystemName);
    }

    public function searchCriteriaPostId(): PostId
    {
        $postId = $this->post->ids->filterBySystem($this->fromSystemName)->first();
        if (null === $postId) {
            throw new \RuntimeException('SearchCriteriaPostId not found for system: ' . $this->fromSystemName);
        }
        return $postId;
    }
}