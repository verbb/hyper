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

    public function onSaveField(FieldEvent $event): void
    {
        // Skip this when updating Craft is currently in progress
        if (Craft::$app->getIsInMaintenanceMode()) {
            return;
        }

        // Only want existing fields
        if ($event->isNew) {
            return;
        }

        $field = $event->field;

        // Only proceed if the field handle changed
        if ($field->handle === $field->oldHandle) {
            return;
        }

        $result = (new Query())
            ->select(['*'])
            ->from([FieldCacheRecord::tableName()])
            ->where(['targetField' => $field->uid])
            ->all();

        $fieldsService = Craft::$app->getFields();

        foreach ($result as $value) {
            $fieldUid = $value['sourceField'];

            $hyperField = $fieldsService->getFieldByUid($fieldUid);

            if ($hyperField instanceof HyperField) {
                $this->updateContentField($hyperField, $field->oldHandle, $field->handle);
            }
        }
    }

    public function onDeleteField(FieldEvent $event): void
    {
        $field = $event->field;
    }

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

    public function updateContentField(HyperField $field, string $oldHandle, string $handle): void
    {
        $contentTable = '{{%content}}';
        $column = ElementHelper::fieldColumn($field->columnPrefix, $field->handle, $field->columnSuffix);

        // Check if this field is in a Matrix field
        if (str_contains($field->context, 'matrixBlockType')) {
            // Get the Matrix field, and the content table
            $blockTypeUid = explode(':', $field->context)[1];

            $matrixInfo = (new Query())
                ->select(['fieldId', 'handle'])
                ->from('{{%matrixblocktypes}}')
                ->where(['uid' => $blockTypeUid])
                ->one();

            if ($matrixInfo) {
                $matrixFieldId = $matrixInfo['fieldId'];
                $matrixBlockTypeHandle = $matrixInfo['handle'];

                $matrixField = Craft::$app->getFields()->getFieldById($matrixFieldId);

                if ($matrixField) {
                    $contentTable = $matrixField->contentTable;

                    $column = ElementHelper::fieldColumn($field->columnPrefix, $matrixBlockTypeHandle . '_' . $field->handle, $field->columnSuffix);
                }
            }
        }

        // Check if this field is in a Super Table field
        if (str_contains($field->context, 'superTableBlockType')) {
            // Get the Super Table field, and the content table
            $blockTypeUid = explode(':', $field->context)[1];

            $superTableFieldId = (new Query())
                ->select(['fieldId'])
                ->from('{{%supertableblocktypes}}')
                ->where(['uid' => $blockTypeUid])
                ->scalar();

            $superTableField = Craft::$app->getFields()->getFieldById($superTableFieldId);

            if ($superTableField) {
                $contentTable = $superTableField->contentTable;
            }
        }

        $hyperFieldContent = (new Query())
            ->select([$column, 'id', 'elementId'])
            ->from($contentTable)
            ->where(['not', [$column => null]])
            ->andWhere(['not', [$column => '']])
            ->all();

        foreach ($hyperFieldContent as $row) {
            $modifiedContent = false;
            $content = Json::decode($row[$column]);

            foreach ($content as $linkData) {
                $fieldData = $linkData['fields'] ?? [];

                if (array_key_exists($oldHandle, $fieldData)) {
                    $modifiedContent = true;

                    ArrayHelper::rename($linkData['fields'], $oldHandle, $handle);
                }
            }

            if ($modifiedContent) {
                Db::update($contentTable, [$column => Json::encode($content)], ['id' => $row['id']]);
            }
        }
    }
}
