<?php
namespace verbb\hyper\content;

final class ModifyResult
{
    // Properties
    // =========================================================================

    public int $matched = 0;
    public int $modified = 0;
    public int $wouldModify = 0;
    public array $modifications = [];


    // Public Methods
    // =========================================================================

    public function getChangedCount(bool $dryRun): int
    {
        return $dryRun ? $this->wouldModify : $this->modified;
    }

    public function recordModification(ContentRef $ref, mixed $value): void
    {
        $this->modifications[] = [
            'ref' => $ref,
            'value' => $value,
        ];
    }
}
