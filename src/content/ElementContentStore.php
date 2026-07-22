<?php
namespace verbb\hyper\content;

use verbb\hyper\fields\HyperField;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\helpers\Db;
use craft\helpers\Json;

use yii\base\InvalidArgumentException;
use yii\db\Connection;
use yii\db\Expression;

class ElementContentStore
{
    // Properties
    // =========================================================================

    private Connection $_db;


    // Public Methods
    // =========================================================================

    public function __construct(?Connection $db = null)
    {
        $this->_db = $db ?? Craft::$app->getDb();
    }

    public function findLayoutUids(FieldInterface $field): array
    {
        $uids = [];

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            try {
                $fieldLayoutField = $layout->getField(fn(BaseField $layoutField) => (
                    $layoutField instanceof CustomField && $layoutField->getFieldUid() === $field->uid
                ));

                if ($fieldLayoutField) {
                    $uids[] = $fieldLayoutField->uid;
                }
            } catch (InvalidArgumentException) {
            }
        }

        return $uids;
    }

    public static function decodeStored(mixed $stored): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (is_string($stored)) {
            return Json::decodeIfJson($stored) ?? $stored;
        }

        return $stored;
    }

    public function eachFieldValue(
        FieldInterface $field,
        callable $callback,
        ?ModifyOptions $options = null,
    ): ModifyResult {
        $options ??= new ModifyOptions();
        $options = $this->_withExpandedElementIds($field, $options);
        $result = new ModifyResult();

        foreach ($this->findLayoutUids($field) as $layoutUid) {
            $this->_eachTopLevelValueForLayoutUid($field, $layoutUid, $callback, $options, $result);
        }

        if ($options->includeNested) {
            $finder = new NestedFieldPlacementFinder($this);

            foreach ($finder->findPlacements($field) as $placement) {
                $this->_eachNestedValueForPlacement($field, $placement, $callback, $options, $result);
            }
        }

        return $result;
    }

    public function eachTopLevelValue(
        FieldInterface $field,
        callable $callback,
        ?ModifyOptions $options = null,
    ): ModifyResult {
        $options ??= new ModifyOptions(includeNested: false);

        return $this->eachFieldValue($field, $callback, $options);
    }

    public function saveRow(int $rowId, array $content): void
    {
        Db::update('{{%elements_sites}}', ['content' => $content ?: null], ['id' => $rowId], db: $this->_db);
    }


    // Private Methods
    // =========================================================================

    private function _eachTopLevelValueForLayoutUid(
        FieldInterface $field,
        string $layoutUid,
        callable $callback,
        ModifyOptions $options,
        ModifyResult $result,
    ): void {
        $sql = $this->_db->getQueryBuilder()->jsonExtract('content', [$layoutUid]);

        $query = (new Query())
            ->select(['content', 'id', 'elementId', 'siteId'])
            ->from('{{%elements_sites}}')
            ->where([
                'and',
                ['not', ['content' => null]],
                $sql . ' IS NOT NULL',
            ]);

        $this->_applyContentQueryFilters($query, $options);

        foreach ($query->each(db: $this->_db) as $row) {
            if (!Json::isJsonObject($row['content'])) {
                continue;
            }

            $elementContent = Json::decode($row['content']) ?? [];
            $fieldContent = &$elementContent[$layoutUid];

            if ($fieldContent === null || $fieldContent === '') {
                continue;
            }

            $result->matched++;

            $ref = new ContentRef(
                rowId: (int)$row['id'],
                elementId: (int)$row['elementId'],
                siteId: (int)$row['siteId'],
                layoutUid: $layoutUid,
                jsonPath: $layoutUid,
                value: $fieldContent,
                parentContent: $elementContent,
            );

            $changed = $callback($ref, $options, $result);

            if (!$changed) {
                continue;
            }

            if ($options->dryRun) {
                $result->wouldModify++;
                continue;
            }

            $elementContent[$layoutUid] = $ref->value;
            $this->saveRow((int)$row['id'], $elementContent);
            $result->modified++;
        }
    }

    private function _eachNestedValueForPlacement(
        FieldInterface $field,
        NestedFieldPlacement $placement,
        callable $callback,
        ModifyOptions $options,
        ModifyResult $result,
    ): void {
        $hostLayoutUid = $placement->hostLayoutUid;
        $sql = $this->_db->getQueryBuilder()->jsonExtract('content', [$hostLayoutUid]);

        $query = (new Query())
            ->select(['content', 'id', 'elementId', 'siteId'])
            ->from('{{%elements_sites}}')
            ->where([
                'and',
                ['not', ['content' => null]],
                $sql . ' IS NOT NULL',
            ]);

        $this->_applyContentQueryFilters($query, $options);

        foreach ($query->each(db: $this->_db) as $row) {
            if (!Json::isJsonObject($row['content'])) {
                continue;
            }

            $elementContent = Json::decode($row['content']) ?? [];
            $hostStored = &$elementContent[$hostLayoutUid];

            if ($hostStored === null || $hostStored === '') {
                continue;
            }

            if (is_string($hostStored)) {
                $hostValue = self::decodeStored($hostStored);

                if (!is_array($hostValue)) {
                    continue;
                }

                $hostStored = $hostValue;
            } else {
                $hostValue = &$hostStored;
            }

            $locations = $placement->locator->locateInHostValue(
                $hostValue,
                $placement->targetFieldHandle,
                $hostLayoutUid,
            );

            if (!$locations) {
                continue;
            }

            $rowChanged = false;

            foreach ($locations as $location) {
                $result->matched++;

                $ref = new ContentRef(
                    rowId: (int)$row['id'],
                    elementId: (int)$row['elementId'],
                    siteId: (int)$row['siteId'],
                    layoutUid: $hostLayoutUid,
                    jsonPath: $location->jsonPath,
                    value: $location->value,
                    parentContent: $elementContent,
                );

                $changed = $callback($ref, $options, $result);

                if (!$changed) {
                    continue;
                }

                if ($options->dryRun) {
                    $result->wouldModify++;
                    continue;
                }

                $rowChanged = true;
            }

            if (!$rowChanged) {
                continue;
            }

            $elementContent[$hostLayoutUid] = $hostValue;
            $this->saveRow((int)$row['id'], $elementContent);
            $result->modified++;
        }
    }

    private function _applyContentQueryFilters(Query $query, ModifyOptions $options): void
    {
        if ($options->elementIds) {
            $query->andWhere(['elementId' => $options->elementIds]);
        }

        if ($options->contentContains !== null && $options->contentContains !== '') {
            $query->andWhere([
                'like',
                new Expression('CAST([[content]] AS CHAR(16383))'),
                $options->contentContains,
            ]);
        }
    }

    private function _withExpandedElementIds(FieldInterface $field, ModifyOptions $options): ModifyOptions
    {
        if (!$options->elementIds || !$options->includeNested || !$field instanceof HyperField) {
            return $options;
        }

        $expanded = $options->elementIds;
        $finder = new NestedFieldPlacementFinder($this);

        foreach ($finder->findPlacements($field) as $placement) {
            if (!$placement->hostField instanceof Matrix) {
                continue;
            }

            $blockIds = Entry::find()
                ->ownerId($options->elementIds)
                ->fieldId($placement->hostField->id)
                ->status(null)
                ->ids();

            if ($blockIds) {
                $expanded = [...$expanded, ...$blockIds];
            }
        }

        $expanded = array_values(array_unique(array_map('intval', $expanded)));

        if ($expanded === $options->elementIds) {
            return $options;
        }

        return new ModifyOptions(
            dryRun: $options->dryRun,
            syncRelations: $options->syncRelations,
            includeNested: $options->includeNested,
            elementIds: $expanded,
            contentContains: $options->contentContains,
            db: $options->db,
        );
    }
}
