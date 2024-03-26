<?php
namespace verbb\hyper\services;

use verbb\hyper\fields\HyperField;
use verbb\hyper\records\FieldCache as FieldCacheRecord;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\FieldEvent;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;

class FieldCache extends Component
{
    // Public Methods
    // =========================================================================

    public function setCache(HyperField $field): void
    {
        $fieldIds = [];

        // For all custom fields in the Hyper field, save a reference to them in a cache
        // But first, remove all cached fields (if any) so we start fresh
        Db::delete(FieldCacheRecord::tableName(), [
            'sourceField' => $field->uid,
        ]);

        foreach ($field->linkTypes as $linkType) {
            $tabs = $linkType['layoutConfig']['tabs'] ?? [];

            foreach ($tabs as $tab) {
                $elements = $tab['elements'] ?? [];

                foreach ($elements as $element) {
                    if ($element['type'] === CustomField::class) {
                        $fieldIds[] = [$field->uid, $element['fieldUid']];
                    }
                }
            }
        }

        Db::batchInsert(FieldCacheRecord::tableName(), ['sourceField', 'targetField'], $fieldIds);
    }
}
