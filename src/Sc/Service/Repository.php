<?php

declare(strict_types=1);

namespace Sc\Service;

use Sc\Model\PostId;
use Sc\Model\PostIdCollection;

/**
 * Universal repository for storing relationships between posts from any social networks
 * Supports v3 format: {"vk": "12345", "tg": "67,68,69"}
 */
class Repository implements \JsonSerializable
{
    private array $items = [];

    private function __construct(
        private readonly string $storagePath
    ) {
    }

    /**
     * Adds collection of relationships for one criteria post
     */
    public function addCollection(PostId $criteriaPostId, PostIdCollection $newPostIdCollection): void
    {
        foreach ($newPostIdCollection as $postId) {
            $this->add($criteriaPostId, $postId);
        }
        $this->save();
    }

    /**
     * Adds relationship between posts (internal method)
     */
    private function add(PostId $criteriaPostId, PostId $newPostId): void
    {
        // Look for existing record for this post
        $existingIndex = $this->findPostIdIndex($criteriaPostId);

        if ($existingIndex !== null) {
            $existingPostIdsOfNewPostDSystem = [];
            if (isset($this->items[$existingIndex][$newPostId->systemName])) {
                $existingPostIdsOfNewPostDSystem = explode(',', $this->items[$existingIndex][$newPostId->systemName]);
            }
            if (!in_array($newPostId->id, $existingPostIdsOfNewPostDSystem, true)) {
                $existingPostIdsOfNewPostDSystem[] = $newPostId->id;
                $this->items[$existingIndex][$newPostId->systemName] = implode(',', $existingPostIdsOfNewPostDSystem);
            }
        } else {
            // Create new record
            $this->items[] = [
                $criteriaPostId->systemName => $criteriaPostId->id,
                $newPostId->systemName => $newPostId->id
            ];
        }
    }

    /**
     * Finds related posts by given PostId
     */
    public function find(PostId $postId): ?PostIdCollection
    {
        $index = $this->findPostIdIndex($postId);
        if ($index === null) {
            return null;
        }
        $item = $this->items[$index];
        $collection = new PostIdCollection();
        foreach ($item as $systemName => $ids) {
            self::addToPostIdCollection($collection, explode(',', $ids), $systemName);
        }
        return $collection;
    }

    /**
     * Finds record index by PostId
     */
    private function findPostIdIndex(PostId $criteriaPostId): ?int
    {
        foreach ($this->items as $index => $item) {
            if (isset($item[$criteriaPostId->systemName])
                && in_array($criteriaPostId->id, explode(',', $item[$criteriaPostId->systemName]), true)
            ) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Adds IDs to post collection
     */
    private static function addToPostIdCollection(PostIdCollection $collection, array $ids, string $systemName): void
    {
        foreach ($ids as $id) {
            $postId = new PostId((string) $id, $systemName);
            if (!$collection->contains($postId)) {
                $collection->add($postId);
            }
        }
    }

    /**
     * Returns number of records
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Saves data to file
     */
    private function save(): void
    {
        $data = $this->jsonSerialize();

        $jsonOutput = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->storagePath, $jsonOutput);
    }

    /**
     * JSON serialization in v3 format
     */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items
        ];
    }

    /**
     * Loads data from file
     */
    public static function load(string $storagePath): Repository
    {
        $repository = new self($storagePath);

        // Try to load v3 format
        if (file_exists($storagePath)) {
            $repository->loadFromV3File($storagePath);
        }
        return $repository;
    }

    /**
     * Loads data from v3 format file
     */
    private function loadFromV3File(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (!$data || !is_array($data)) {
            return;
        }

        if (isset($data['items']) && is_array($data['items'])) {
            $this->loadFromArray($data['items']);
        }
    }

    /**
     * Loads data from array (for migration and file loading)
     */
    private function loadFromArray(array $items): void
    {
        $this->items = $items;
    }
}
