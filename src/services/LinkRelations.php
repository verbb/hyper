<?php
namespace verbb\hyper\services;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkCollectionInterface;
use verbb\hyper\records\LinkRelation as LinkRelationRecord;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\helpers\Db;
use craft\helpers\ElementHelper;

use Throwable;

use benf\neo\Field as NeoField;

class LinkRelations extends Component
{
    // Properties
    // =========================================================================

    public bool $enableRequestPriming = true;

    private array $_hydratedElements = [];
    private array $_pendingOwners = [];
    private array $_linkedElementWith = [];
    private bool $_ownersPrimed = false;


    // Public Methods
    // =========================================================================

    public function syncFromLinkCollection(HyperField $field, ElementInterface $element, LinkCollectionInterface $collection): bool
    {
        if ($element->isProvisionalDraft || ElementHelper::isDraftOrRevision($element)) {
            return true;
        }

        if (!$element->id || !$element->siteId) {
            return true;
        }

        $rows = [];

        foreach ($collection->getLinks() as $sortOrder => $link) {
            if (!$link instanceof LinkInterface) {
                continue;
            }

            $target = $this->_extractElementTarget($link, $element);

            if ($target === null) {
                continue;
            }

            $rows[] = [
                'fieldId' => $field->id,
                'ownerId' => $element->id,
                'ownerSiteId' => $element->siteId,
                'sortOrder' => (int)$sortOrder,
                'linkTypeHandle' => (string)($link->handle ?? ''),
                'targetId' => $target['targetId'],
                'targetSiteId' => $target['targetSiteId'],
                'targetType' => $target['targetType'],
            ];
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            LinkRelationRecord::deleteAll([
                'fieldId' => $field->id,
                'ownerId' => $element->id,
                'ownerSiteId' => $element->siteId,
            ]);

            foreach ($rows as $row) {
                $record = new LinkRelationRecord();
                $record->setAttributes($row, false);

                if (!$record->save(false)) {
                    $transaction->rollBack();

                    return false;
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    public function getRelationRowsForOwners(array $owners): array
    {
        if (!$owners) {
            return [];
        }

        $query = (new Query())
            ->from(['{{%hyper_links}}'])
            ->orderBy(['sortOrder' => SORT_ASC]);

        $conditions = array_map(static function(array $owner): array {
            $condition = [
                'ownerId' => $owner['ownerId'],
                'ownerSiteId' => $owner['ownerSiteId'],
            ];

            if (!empty($owner['fieldId'])) {
                $condition['fieldId'] = $owner['fieldId'];
            }

            return $condition;
        }, $owners);

        $query->where(array_merge(['or'], $conditions));

        return $query->all();
    }

    public function registerOwner(int $ownerId, int $ownerSiteId): void
    {
        if (!$this->enableRequestPriming) {
            return;
        }
        if (!$ownerId || !$ownerSiteId) {
            return;
        }

        $this->_pendingOwners[$ownerId . ':' . $ownerSiteId] = [
            'ownerId' => $ownerId,
            'ownerSiteId' => $ownerSiteId,
        ];
    }

    public function registerElementForPriming(?ElementInterface $element): void
    {
        if (!$element?->id || !$element->siteId) {
            return;
        }

        $this->registerOwner($element->id, $element->siteId);

        $fieldLayout = $element->getFieldLayout();

        if (!$fieldLayout) {
            return;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $this->_registerNestedOwnersFromField($element, $field);
        }
    }

    public function primePendingOwners(): void
    {
        if ($this->_ownersPrimed || !$this->_pendingOwners) {
            return;
        }

        // Mark primed before batch queries so nested populate events cannot recurse.
        $this->_ownersPrimed = true;
        $this->primeElementsForOwners(array_values($this->_pendingOwners));
    }

    public function resetRequestState(): void
    {
        $this->_pendingOwners = [];
        $this->_ownersPrimed = false;
        $this->_hydratedElements = [];
        $this->_linkedElementWith = [];
    }

    /**
     * Register Craft-style eager-load paths for fields on linked target elements.
     *
     * Example owner query: ->with(['navLink.linkedElements.thumbnail'])
     * registers `thumbnail` for the Hyper field's linked element batch query.
     */
    public function registerLinkedElementWith(int $fieldId, string $withPath): void
    {
        if (!$fieldId) {
            return;
        }

        $paths = $this->_linkedElementWith[$fieldId] ?? [];

        if ($withPath !== '' && !in_array($withPath, $paths, true)) {
            $this->_linkedElementWith[$fieldId][] = $withPath;

            if ($this->_ownersPrimed) {
                $this->_ownersPrimed = false;
                $this->primePendingOwners();
            }
        }
    }

    public function getLinkedElementWithForField(int $fieldId): array
    {
        return array_values(array_unique(array_filter(
            $this->_linkedElementWith[$fieldId] ?? [],
            static fn(string $path): bool => $path !== '',
        )));
    }

    public function primeElementsForOwners(array $owners): void
    {
        $relationRows = $this->getRelationRowsForOwners($owners);
        $this->primeElementsForRelationRows($relationRows);
    }

    public function primeElementsForRelationRows(array $relationRows): void
    {
        $grouped = [];
        $fieldIds = [];

        foreach ($relationRows as $row) {
            $targetId = (int)($row['targetId'] ?? 0);
            $targetType = (string)($row['targetType'] ?? '');

            if (!$targetId || !$targetType || !is_subclass_of($targetType, ElementInterface::class)) {
                continue;
            }

            if (!empty($row['fieldId'])) {
                $fieldIds[(int)$row['fieldId']] = (int)$row['fieldId'];
            }

            $targetSiteId = isset($row['targetSiteId']) ? (int)$row['targetSiteId'] : null;
            $grouped[$targetType][$this->_elementCacheKey($targetId, $targetSiteId)] = [
                'targetId' => $targetId,
                'targetSiteId' => $targetSiteId,
            ];
        }

        $withPaths = [];

        foreach ($fieldIds as $fieldId) {
            $withPaths = [...$withPaths, ...$this->getLinkedElementWithForField($fieldId)];
        }

        $withPaths = array_values(array_unique($withPaths));

        foreach ($grouped as $elementType => $targets) {
            $ids = array_values(array_unique(array_column($targets, 'targetId')));

            if (!$ids) {
                continue;
            }

            $siteIds = array_values(array_unique(array_filter(array_column($targets, 'targetSiteId'))));

            $query = $elementType::find()
                ->id($ids)
                ->status(null);

            if (count($siteIds) === 1) {
                $query->siteId($siteIds[0]);
            } elseif ($siteIds) {
                $query->siteId($siteIds);
            }

            if ($withPaths !== []) {
                $query->with($withPaths);
            }

            foreach ($query->all() as $element) {
                $this->_hydratedElements[$this->_elementCacheKey($element->id, $element->siteId)] = $element;
                $this->_hydratedElements[$this->_elementCacheKey($element->id, null)] = $element;
            }
        }
    }

    public function getPrimedElement(int $targetId, ?int $targetSiteId): ?ElementInterface
    {
        if ($element = $this->_hydratedElements[$this->_elementCacheKey($targetId, $targetSiteId)] ?? null) {
            return $element;
        }

        if ($targetSiteId !== null) {
            return $this->_hydratedElements[$this->_elementCacheKey($targetId, null)] ?? null;
        }

        return null;
    }

    public function clearPrimedElements(): void
    {
        $this->_hydratedElements = [];
    }

    public function getRelatedElementsQuery(array $params = []): ?ElementQueryInterface
    {
        $fieldHandle = $params['relatedTo']['field'] ?? null;
        $targetElement = $params['relatedTo']['targetElement'] ?? null;

        if (!$fieldHandle || !$targetElement) {
            return null;
        }

        $hyperField = Craft::$app->getFields()->getFieldByHandle($fieldHandle);

        if (!$hyperField instanceof HyperField) {
            return null;
        }

        $elementType = $params['elementType'] ?? Entry::class;

        $result = (new Query())
            ->select(['ownerId AS id', 'ownerSiteId AS siteId'])
            ->from(['{{%hyper_links}}'])
            ->where([
                'fieldId' => $hyperField->id,
                'targetType' => $elementType,
                'targetId' => $targetElement->id,
                'targetSiteId' => $targetElement->siteId,
            ])
            ->indexBy(static fn(array $row): string => $row['id'] . ':' . $row['siteId'])
            ->all();

        $elementParams = [];

        foreach ($result as $value) {
            $elementParams['id'][] = $value['id'];
            $elementParams['siteId'][] = $value['siteId'];
        }

        if (!$elementParams) {
            $elementParams['id'] = -1;
        }

        if (isset($params['site'])) {
            $elementParams['site'] = $params['site'];
        } else {
            $elementParams['site'] = Craft::$app->getSites()->getCurrentSite()->handle;
        }

        if (isset($params['criteria']) && is_array($params['criteria'])) {
            foreach ($params['criteria'] as $key => $value) {
                if ($key === 'id') {
                    $elementParams['where'] = Db::parseParam('id', $value);
                } else {
                    $elementParams[$key] = $value;
                }
            }
        }

        $elementQuery = $elementType::find();
        Craft::configure($elementQuery, $elementParams);

        return $elementQuery;
    }

    public function clearRelations(): void
    {
        Db::truncateTable('{{%hyper_links}}');
    }

    public function backfillFromElementCache(): int
    {
        $db = Craft::$app->getDb();

        if (!$db->tableExists('{{%hyper_element_cache}}') || !$db->tableExists('{{%hyper_links}}')) {
            return 0;
        }

        $rows = (new Query())
            ->from(['{{%hyper_element_cache}}'])
            ->where(['not', ['targetId' => null]])
            ->orderBy([
                'sourceId' => SORT_ASC,
                'sourceSiteId' => SORT_ASC,
                'fieldId' => SORT_ASC,
                'id' => SORT_ASC,
            ])
            ->all();

        if (!$rows) {
            return 0;
        }

        $inserted = 0;
        $sortOrders = [];

        foreach ($rows as $row) {
            $ownerKey = $row['sourceId'] . ':' . $row['sourceSiteId'] . ':' . $row['fieldId'];
            $sortOrders[$ownerKey] = ($sortOrders[$ownerKey] ?? -1) + 1;

            $targetType = (string)($row['targetType'] ?? '');
            $linkTypeHandle = $targetType !== ''
                ? 'default-' . \craft\helpers\StringHelper::toKebabCase($targetType)
                : '';

            $exists = (new Query())
                ->from(['{{%hyper_links}}'])
                ->where([
                    'fieldId' => $row['fieldId'],
                    'ownerId' => $row['sourceId'],
                    'ownerSiteId' => $row['sourceSiteId'],
                    'targetId' => $row['targetId'],
                ])
                ->exists();

            if ($exists) {
                continue;
            }

            $record = new LinkRelationRecord();
            $record->fieldId = (int)$row['fieldId'];
            $record->ownerId = (int)$row['sourceId'];
            $record->ownerSiteId = (int)$row['sourceSiteId'];
            $record->sortOrder = $sortOrders[$ownerKey];
            $record->linkTypeHandle = $linkTypeHandle;
            $record->targetId = (int)$row['targetId'];
            $record->targetSiteId = $row['targetSiteId'] !== null
                ? (int)$row['targetSiteId']
                : (int)$row['sourceSiteId'];
            $record->targetType = $targetType ?: null;

            if ($record->save(false)) {
                $inserted++;
            }
        }

        return $inserted;
    }


    // Private Methods
    // =========================================================================

    private function _registerNestedOwnersFromField(ElementInterface $element, FieldInterface $field): void
    {
        if ($field instanceof Matrix) {
            $blocks = $element->getFieldValue($field->handle);

            if (!is_iterable($blocks)) {
                return;
            }

            foreach ($blocks as $block) {
                if ($block instanceof ElementInterface) {
                    $this->registerElementForPriming($block);
                }
            }

            return;
        }

        if (class_exists(NeoField::class) && $field instanceof NeoField) {
            $blocks = $element->getFieldValue($field->handle);

            if (!is_iterable($blocks)) {
                return;
            }

            foreach ($blocks as $block) {
                if ($block instanceof ElementInterface) {
                    $this->registerElementForPriming($block);
                }
            }
        }
    }

    private function _extractElementTarget(LinkInterface $link, ElementInterface $owner): ?array
    {
        if (!$link instanceof ElementLink) {
            return null;
        }

        $linkValue = $link->linkValue;

        if (is_array($linkValue)) {
            $targetId = (int)($linkValue[0] ?? 0) ?: null;
        } else {
            $targetId = (int)$linkValue ?: null;
        }

        if (!$targetId) {
            return null;
        }

        return [
            'targetId' => $targetId,
            'targetSiteId' => $link->linkSiteId ? (int)$link->linkSiteId : (int)$owner->siteId,
            'targetType' => $link::elementType(),
        ];
    }

    private function _elementCacheKey(int $targetId, ?int $targetSiteId): string
    {
        return $targetId . ':' . ($targetSiteId ?? 'null');
    }
}
