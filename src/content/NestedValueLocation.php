<?php
namespace verbb\hyper\content;

final class NestedValueLocation
{
    // Public Methods
    // =========================================================================

    public function __construct(
        public array &$hostValue,
        public mixed &$value,
        public string $jsonPath,
    ) {
    }
}
