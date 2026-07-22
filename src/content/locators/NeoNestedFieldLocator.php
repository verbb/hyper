<?php
namespace verbb\hyper\content\locators;

use craft\base\FieldInterface;
use craft\models\FieldLayout;

class NeoNestedFieldLocator extends FieldsMapNestedFieldLocator
{
    // Public Methods
    // =========================================================================

    public static function hostFieldClass(): string
    {
        return \benf\neo\Field::class;
    }


    // Protected Methods
    // =========================================================================

    protected function _getNestedLayouts(FieldInterface $hostField): iterable
    {
        if (!$hostField instanceof \benf\neo\Field) {
            return;
        }

        foreach ($hostField->getBlockTypes() as $blockType) {
            yield $blockType->getFieldLayout();
        }
    }
}
