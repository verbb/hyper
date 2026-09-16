<?php
namespace verbb\hyper\content;

use verbb\hyper\content\locators\VizyNestedFieldLocator;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\helpers\Db;
use craft\helpers\Json;

use yii\db\Connection;
use yii\db\Expression;

use RuntimeException;

class ElementContentStore
{
    // Static Methods
    // =========================================================================

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


    // Properties
    // =========================================================================

    private Connection $_db;
    private ?RawContent $_rawContent = null;


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
            // One field can have several independently stored occurrences in a layout.
            foreach ($layout->getCustomFieldElements() as $fieldLayoutField) {
                if ($fieldLayoutField->getFieldUid() === $field->uid) {
                    $uids[] = $fieldLayoutField->uid;
                }
            }
        }

        return array_values(array_unique($uids));
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
            $this->_eachEmbeddedValue($field, $callback, $options, $result);

            $finder = new NestedFieldPlacementFinder($this);

            foreach ($finder->findPlacements($field) as $placement) {
                if ($placement->locator instanceof VizyNestedFieldLocator
                    && method_exists(Craft::$app->getPlugins()->getPlugin('vizy')?->getContent() ?? new \stdClass(), 'getRawContentAdapter')) {
                    continue;
                }

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

    public function saveRow(int $rowId, array $content, ?string $sourceContent = null): void
    {
        $this->_db->transaction(function() use ($rowId, $content, $sourceContent): void {
            // A transform operates on a snapshot of the entire JSON row. Lock and
            // compare before replacing it so a later edit cannot be silently lost.
            $current = $this->_db->createCommand('SELECT [[content]] FROM {{%elements_sites}} WHERE [[id]] = :id FOR UPDATE', [':id' => $rowId])->queryScalar();

            if ($current === false || ($sourceContent !== null && $current !== $sourceContent)) {
                throw new RuntimeException("Content changed concurrently at row {$rowId}.");
            }

            // Associative decoding collapses {} and numeric-keyed objects to arrays.
            // Preserve those shapes wherever the migration did not change a value.
            $value = is_string($current) ? RawJson::preserve($current, $content) : $content;
            Db::update('{{%elements_sites}}', [
                'content' => $content ? new Expression(':content', [':content' => RawJson::encode($value)]) : null,
            ], ['id' => $rowId], db: $this->_db);
            $this->_rawContent ??= new RawContent();
            $this->_rawContent->invalidateAfterCommit($this->_db);
        });
    }


    // Private Methods
    // =========================================================================

    private function _eachEmbeddedValue(FieldInterface $field, callable $callback, ModifyOptions $options, ModifyResult $result): void
    {
        $content = \verbb\hyper\Hyper::$plugin->getContent();
        $map = $content->captureFieldLocations($field->uid);
        $run = function() use ($content, $map, $callback, $options, $result): void {
            $changed = $content->modifyFieldValues($map, function(mixed $raw, array $context) use ($callback, $options, $result): array {
                $parent = [];
                $ref = new ContentRef(
                    rowId: $context['rowId'], elementId: $context['elementId'], siteId: $context['siteId'],
                    layoutUid: $context['rootPlacementUid'], jsonPath: Json::encode($context['path']),
                    value: $raw, parentContent: $parent, hasDurableOwner: false,
                );
                $result->matched++;
                if (!$callback($ref, $options, $result)) {
                    return Change::unchanged();
                }
                if ($options->dryRun) {
                    $result->wouldModify++;
                }
                return Change::replace($ref->value);
            }, ['db' => $this->_db, 'dryRun' => $options->dryRun, 'elementIds' => $options->elementIds,
                'contentContains' => $options->contentContains]);
            $result->modified += $changed['modified'];
        };
        // Legacy store callers did not have to open a transaction. Preserve that
        // convenience while the raw API itself keeps explicit transaction ownership.
        if ($options->dryRun || $this->_db->getTransaction()?->getIsActive()) {
            $run();
        } else {
            $this->_db->transaction($run);
        }
    }

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
            $this->saveRow((int)$row['id'], $elementContent, $row['content']);
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

            $hostWasEncoded = is_string($hostStored);

            if ($hostWasEncoded) {
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
                    hasDurableOwner: !($placement->locator instanceof VizyNestedFieldLocator),
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

            // Preserve the host field storage contract (Vizy stores an encoded string).
            $originalHost = Json::decode($row['content'])[$hostLayoutUid];
            $elementContent[$hostLayoutUid] = $hostWasEncoded ? RawJson::encode(RawJson::preserve($originalHost, $hostValue)) : $hostValue;
            $this->saveRow((int)$row['id'], $elementContent, $row['content']);
            $result->modified++;
        }
    }

    private function _applyContentQueryFilters(Query $query, ModifyOptions $options): void
    {
        if ($options->elementIds !== null) {
            $query->andWhere(['elementId' => $options->elementIds]);
        }

        if ($options->contentContains !== null && $options->contentContains !== '') {
            $query->andWhere([
                'like',
                new Expression('CAST([[content]] AS ' . ($this->_db->getDriverName() === 'pgsql' ? 'TEXT' : 'CHAR') . ')'),
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

        $expandedOptions = clone $options;
        $expandedOptions->elementIds = $expanded;

        return $expandedOptions;
    }
}
