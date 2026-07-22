<?php
namespace verbb\hyper\content\locators;

use craft\base\FieldInterface;
use craft\fields\Matrix;
use craft\models\FieldLayout;

class MatrixNestedFieldLocator extends FieldsMapNestedFieldLocator
{
    // Public Methods
    // =========================================================================

    public static function hostFieldClass(): string
    {
        return Matrix::class;
    }


    // Protected Methods
    // =========================================================================

    protected function _getNestedLayouts(FieldInterface $hostField): iterable
    {
        if (!$hostField instanceof Matrix) {
            return;
        }

        foreach ($hostField->getEntryTypes() as $entryType) {
            yield $entryType->getFieldLayout();
        }
    }
}
