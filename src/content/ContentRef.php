<?php
namespace verbb\hyper\content;

final class ContentRef
{
    // Public Methods
    // =========================================================================

    public function __construct(
        public readonly int $rowId,
        public readonly int $elementId,
        public readonly int $siteId,
        public readonly string $layoutUid,
        public readonly string $jsonPath,
        public mixed &$value,
        public array &$parentContent,
    ) {
    }
}
