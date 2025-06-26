<?php

namespace Sc;
class Storage implements \JsonSerializable
{
    private int $lastDate;

    private LimitedList $lastIds;

    public function __construct()
    {
        $this->lastDate = time();
        $this->lastIds  = new LimitedList(2000000);
    }

    public function setLastDate(int $lastDate): void
    {
        $this->lastDate = $lastDate;
    }

    public function getLastDate(): int
    {
        return $this->lastDate;
    }

    public function addId(int $id, int $mappedId): void
    {
        $this->lastIds->push([$id => $mappedId]);
    }

    public function hasId( $id): bool
    {
        return $this->lastIds->has($id);
    }

    public function save(): void
    {
        file_put_contents(__DIR__ . '/../../new_storage.json', json_encode($this));
    }

    public function jsonSerialize(): array
    {
        return [
            'date' => $this->lastDate,
            'items' => $this->lastIds,
        ];
    }
    public static function load(): Storage
    {
        $filePath = __DIR__ . '/../../new_storage.json';
        if (!file_exists($filePath)) {
            return new Storage();
        }

        $content = file_get_contents($filePath);
        $content = json_decode($content, true);
        if (empty($content) && !isset($content['date'], $content['items'])) {
            return new Storage();
        }

        $storage = new self();
        $storage->setLastDate((int) $content['date']);
        $storage->lastIds->pushAll($content['items']);

        if (!$storage instanceof Storage) {
            return new Storage();
        }

        return $storage;
    }
}