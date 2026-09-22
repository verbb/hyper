<?php
namespace verbb\hyper\models;

use verbb\hyper\base\LinkInterface;

use craft\base\ElementInterface;

interface LinkCollectionInterface
{
    // Public Methods
    // =========================================================================

    public function getLinks(): array;
    public function all(): array;
    public function empty(?bool $empty = false): self;
    public function where(array $condition): self;
    public function andWhere(array $condition): self;
    public function orWhere(array $condition): self;
    public function orderBy(array|string $columns): self;
    public function limit(?int $limit): self;
    public function offset(int $offset): self;
    public function count(): int;
    public function exists(): bool;
    public function first(): ?LinkInterface;
    public function isEmpty(): bool;
    public function serializeValues(?ElementInterface $element = null): array;
    public function withLinks(array $links): self;
}
