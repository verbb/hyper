<?php
namespace verbb\hyper\content;

use craft\base\FieldInterface;

final class NestedFieldPlacement
{
    // Public Methods
    // =========================================================================

    public function __construct(
        public FieldInterface $hostField,
        public string $hostLayoutUid,
        public string $targetFieldHandle,
        public NestedFieldLocator $locator,
    ) {
    }
}
