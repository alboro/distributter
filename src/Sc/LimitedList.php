<?php

namespace Sc;
class LimitedList implements \JsonSerializable
{
    /** @var int[] */
    private $list;

    /** @var int */
    private $limit;

    public function __construct(int $limit)
    {
        $this->list  = [];
        $this->limit = $limit;
    }

    public function jsonSerialize(): array
    {
        return $this->list;
    }

    /**
     * @param $item
     */
    public function push($item): void
    {
        while (count($this->list) >= $this->limit) {
            array_shift($this->list);
        }

        array_push($this->list, $item);
    }

    /**
     * @param $item
     */
    public function pushAll($items): void
    {
        $this->list = $items;
    }

    /**
     * @param $id
     * @return bool
     */
    public function has($id): bool
    {
        return count(array_filter($this->list, function (array $item) use ($id) { return isset($item[$id]); }));
    }
}