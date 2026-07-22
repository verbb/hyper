<?php
namespace verbb\hyper\content\locators;

use craft\base\FieldInterface;
use craft\models\FieldLayout;

class SuperTableNestedFieldLocator extends FieldsMapNestedFieldLocator
{
    // Public Methods
    // =========================================================================

    public static function hostFieldClass(): string
    {
        return \verbb\supertable\fields\SuperTableField::class;
    }


    // Protected Methods
    // =========================================================================

    protected function _getNestedLayouts(FieldInterface $hostField): iterable
    {
        if (!$hostField instanceof \verbb\supertable\fields\SuperTableField) {
            return;
        }

        if (!method_exists($hostField, 'getBlockTypes')) {
            return;
        }

        foreach ($hostField->getBlockTypes() as $blockType) {
            yield $blockType->getFieldLayout();
        }
    }
}
