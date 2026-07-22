<?php
namespace verbb\hyper\models;

use verbb\hyper\base\LinkInterface;

use craft\base\ElementInterface;

interface LinkCollectionInterface
{
    // Public Methods
    // =========================================================================

    public function getLinks(): array;
    public function first(): ?LinkInterface;
    public function isEmpty(): bool;
    public function serializeValues(?ElementInterface $element = null): array;
    public function withLinks(array $links): self;
}
