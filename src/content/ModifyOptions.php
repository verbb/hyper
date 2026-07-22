<?php
namespace verbb\hyper\content;

use yii\db\Connection;

final class ModifyOptions
{
    // Public Methods
    // =========================================================================

    public function __construct(
        public bool $dryRun = false,
        public bool $syncRelations = true,
        public bool $includeNested = true,
        public ?array $elementIds = null,
        public ?string $contentContains = null,
        public ?Connection $db = null,
    ) {
    }
}
