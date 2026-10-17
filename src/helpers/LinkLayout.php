<?php
namespace verbb\hyper\helpers;

use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;

use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\models\FieldLayoutForm;

class LinkLayout
{
    // Static Methods
    // =========================================================================

    public static function createForm(HyperField $field, LinkInterface $link, bool $static = false, array $config = []): FieldLayoutForm
    {
        $layout = clone $link->getFieldLayout();

        if ($layout->isFieldIncluded('linkValue')) {
            $input = $layout->getField('linkValue');
            $input->field = $field;
            $input->link = $link;
        }

        // JSON-owned Matrix entries need the inline editor, including fields revealed later.
        $matrixModes = [];

        foreach ($layout->getCustomFields() as $customField) {
            if ($customField instanceof Matrix) {
                $matrixModes[] = [$customField, $customField->viewMode];
                $customField->viewMode = Matrix::VIEW_MODE_BLOCKS;
            }
        }

        try {
            return $layout->createForm($link, $static, $config);
        } finally {
            foreach ($matrixModes as [$customField, $mode]) {
                $customField->viewMode = $mode;
            }
        }
    }

    public static function hasElementConditions(LinkInterface $link): bool
    {
        foreach ($link->getFieldLayout()?->getTabs() ?? [] as $tab) {
            if ($tab->getElementCondition()) {
                return true;
            }

            foreach ($tab->getElements() as $element) {
                if ($element->getElementCondition() || ($element instanceof CustomField && $element->getElementEditCondition())) {
                    return true;
                }
            }
        }

        return false;
    }
}
