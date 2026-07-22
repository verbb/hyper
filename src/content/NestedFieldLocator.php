<?php
namespace verbb\hyper\content;

use verbb\hyper\fields\HyperField;

use craft\base\FieldInterface;

interface NestedFieldLocator
{
    // Public Methods
    // =========================================================================

    public static function hostFieldClass(): string;
    public function findNestedFieldHandles(FieldInterface $hostField, HyperField $targetField): array;
    public function locateInHostValue(array &$hostValue, string $targetFieldHandle, string $pathPrefix): array;
}
