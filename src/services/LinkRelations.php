<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkCollectionInterface;
use verbb\hyper\records\LinkRelation as LinkRelationRecord;

use Craft;
use craft\base\Component;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\events\DraftEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;

use Throwable;

class LinkRelations extends Component
{
    // Properties
    // =========================================================================

    public bool $enableRequestPriming = true;

    private array $_hydratedElements = [];
    private array $_pendingOwners = [];
    private array $_linkedElementWith = [];
    private bool $_priming = false;
    private bool $_loadingTargets = false;
    private array $_primedOwners = [];


    // Public Methods
    // =========================================================================

    /**
     * Rebuild all placements of a field; an optional collection replaces the caller's placement.
     * Without a replacement, read every value from the owner (after content migration writes).
     */
    public function syncFromLinkCollection(HyperField $field, ElementInterface $element, ?LinkCollectionInterface $collection = null): bool
    {
        // Vizy nodes have synthetic IDs, not durable element rows. Their content
        // remains in the host field and cannot own a relation-index entry.
        if ($element instanceof \verbb\vizy\elements\Block || $element->trashed
            || $element->isProvisionalDraft || ElementHelper::isDraftOrRevision($element)) {
            return true;
        }

        if (!$element->id || !$element->siteId) {
            return true;
        }

        $rows = [];

        // The index belongs to the underlying field, so replace all its occurrences together.
        foreach ($this->_getOwnerLinks($field, $element, $collection) as $sortOrder => $link) {
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

        if ($rows) {
            // Historical content can retain references after a target is permanently
            // deleted. Preserve that content, but only index targets that still exist.
            // One lookup covers duplicate targets and avoids per-link resolution.
            $targets = (new Query())
                ->select(['id', 'type'])
                ->from('{{%elements}}')
                ->where(['id' => array_values(array_unique(array_column($rows, 'targetId')))])
                ->indexBy('id')
                ->all();
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool =>
                isset($targets[$row['targetId']]) && $targets[$row['targetId']]['type'] === $row['targetType']
            ));
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Element saves run in a transaction, and a plain read there can return an older snapshot
            // than a concurrent save of this element committed. A locking read returns the current rows
            // and holds them until the outer transaction ends, so a concurrent save can't skip its write.
            $command = (new Query())
                ->select(['sortOrder', 'linkTypeHandle', 'targetId', 'targetSiteId', 'targetType'])
                ->from(LinkRelationRecord::tableName())
                ->where([
                    'fieldId' => $field->id,
                    'ownerId' => $element->id,
                    'ownerSiteId' => $element->siteId,
                ])
                ->createCommand($db);
            $storedRows = $db->createCommand($command->getSql() . ' FOR UPDATE', $command->params)->queryAll();

            // Saves that leave the links as they were, resaves included, keep the stored rows, as Craft
            // does for its own relations. The index is rewritten only when it no longer matches.
            if ($this->_relationRowKeys($storedRows) === $this->_relationRowKeys($rows)) {
                $transaction->commit();

                return true;
            }

            LinkRelationRecord::deleteAll([
                'fieldId' => $field->id,
                'ownerId' => $element->id,
                'ownerSiteId' => $element->siteId,
            ]);

            // This is a derived index with no per-record lifecycle. Batch its writes;
            // Craft adds timestamps/UIDs, and the transaction retains the old index on failure.
            foreach (array_chunk($rows, 500) as $batch) {
                $db->createCommand()->batchInsert(
                    LinkRelationRecord::tableName(),
                    array_keys($batch[0]),
                    array_map('array_values', $batch),
                )->execute();
            }

            $transaction->commit();
            unset($this->_primedOwners[$element->id . ':' . $element->siteId]);
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    public function handleAfterApplyDraft(DraftEvent $event): void
    {
        // Applying an unpublished draft keeps its ID and saves one site without propagating
        // or resaving nested elements, and every earlier save was skipped as a draft save.
        if ((int)$event->canonical->id !== (int)$event->draft->id) {
            return;
        }

        $canonical = $event->canonical;

        // Craft has already committed the apply. The index is derived and `sync-relations` rebuilds it,
        // so a failure here is logged rather than shown as a failed apply for an entry that now exists.
        try {
            foreach ($canonical::find()->id($canonical->id)->siteId('*')->status(null)->all() as $element) {
                $this->_syncOwnerTree($element);
            }
        } catch (Throwable $e) {
            Hyper::error("Unable to update link relations for element #{$canonical->id} after applying a draft: {$e->getMessage()} {$e->getFile()}:{$e->getLine()}");
        }
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
        if (!$this->enableRequestPriming || $this->_loadingTargets) {
            return;
        }

        if (!$ownerId || !$ownerSiteId) {
            return;
        }

        $key = $ownerId . ':' . $ownerSiteId;

        if (isset($this->_primedOwners[$key])) {
            return;
        }

        $this->_pendingOwners[$key] = [
            'ownerId' => $ownerId,
            'ownerSiteId' => $ownerSiteId,
        ];
    }

    public function registerElementForPriming(?ElementInterface $element): void
    {
        // Hydrating a target must not recursively traverse its own link graph.
        if (!$this->enableRequestPriming || $this->_loadingTargets || !$element?->id || !$element->siteId) {
            return;
        }

        // Most populated elements (assets, users, categories) have no Hyper field.
        // Registering them would add their IDs to every relation batch query.
        if (!$this->_hasHyperField($element)) {
            return;
        }

        $this->registerOwner($element->id, $element->siteId);

        // Matrix and Neo owners register when Craft actually populates them.
        // Reading unrelated owners must not force their nested queries to run.
    }

    public function primePendingOwners(): void
    {
        if ($this->_priming || $this->_loadingTargets || !$this->_pendingOwners) {
            return;
        }

        // Recursion guard only while draining — later registerOwner() calls must
        // schedule another batch (Astra H3-A10).
        $this->_priming = true;

        try {
            while ($this->_pendingOwners) {
                $batch = $this->_pendingOwners;
                $this->_pendingOwners = [];
                $this->primeElementsForOwners(array_values($batch));
                $this->_primedOwners += $batch;
            }
        } finally {
            $this->_priming = false;
        }
    }

    public function resetRequestState(): void
    {
        $this->_pendingOwners = [];
        $this->_primedOwners = [];
        $this->_priming = false;
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

        $this->_linkedElementWith[$fieldId] ??= [];
        $paths = $this->_linkedElementWith[$fieldId];

        if ($withPath !== '' && !in_array($withPath, $paths, true)) {
            $this->_linkedElementWith[$fieldId][] = $withPath;

            // New eager-load requirements invalidate completed owner batches. The next
            // population boundary drains these together with the incoming query's owners.
            $this->_pendingOwners += $this->_primedOwners;
            $this->_primedOwners = [];
        }
    }

    public function hasRequestedEagerLoading(): bool
    {
        return $this->_linkedElementWith !== [];
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

            $loadingTargets = $this->_loadingTargets;
            $this->_loadingTargets = true;

            try {
                foreach ($query->all() as $element) {
                    $this->_hydratedElements[$this->_elementCacheKey($element->id, $element->siteId)] = $element;
                }
            } finally {
                $this->_loadingTargets = $loadingTargets;
            }
        }
    }

    public function getPrimedElement(int $targetId, ?int $targetSiteId): ?ElementInterface
    {
        if ($targetSiteId === null) {
            return null;
        }

        return $this->_hydratedElements[$this->_elementCacheKey($targetId, $targetSiteId)] ?? null;
    }

    public function clearPrimedElements(): void
    {
        $this->_pendingOwners += $this->_primedOwners;
        $this->_primedOwners = [];
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

        // Owner query type (what we return) vs target type (what is linked).
        $ownerElementType = $params['elementType'] ?? Entry::class;
        $targetType = $params['targetType'] ?? $targetElement::class;

        // Recorded owner/site pairs stay in a subquery. Loading them first and inlining one
        // condition per owner made every query grow with the number of incoming links.
        $ownerPairs = (new Query())
            ->select(['ownerId', 'ownerSiteId'])
            ->from(['{{%hyper_links}}'])
            ->where([
                'fieldId' => $hyperField->id,
                'targetType' => $targetType,
                'targetId' => $targetElement->id,
                'targetSiteId' => $targetElement->siteId,
            ]);

        // Configure applies keys in order. Keeping `siteId` ahead of `site` preserves the
        // existing precedence, where `site` wins over a `siteId` criterion.
        $elementParams = ['siteId' => null];

        // Fixed order sorts by the `id` param, which has always held the linked owner IDs in
        // index order. Load them only for that case so other lookups keep the subquery alone.
        if (!empty($params['criteria']['fixedOrder'])) {
            $elementParams['id'] = array_values(array_unique(array_column($ownerPairs->all(), 'ownerId'))) ?: false;
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

        $elementQuery = $ownerElementType::find();
        Craft::configure($elementQuery, $elementParams);

        // Independent ID/site filters form a cross product. Match each recorded pair as a
        // composite IN, including when callers request all sites or supply additional criteria.
        $elementQuery->andWhere(['in', ['elements.id', 'elements_sites.siteId'], $ownerPairs]);

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

    private function _syncOwnerTree(ElementInterface $element): void
    {
        $synced = [];

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof HyperField) {
                // One sync covers every layout occurrence of the field.
                if (!isset($synced[$field->id])) {
                    $synced[$field->id] = true;
                    $this->syncFromLinkCollection($field, $element);
                }

                continue;
            }

            if (!$field instanceof ElementContainerFieldInterface) {
                continue;
            }

            $value = $element->getFieldValue($field->handle);

            // Matrix and Neo return a query, Content Block returns its single element.
            $nestedElements = match (true) {
                $value instanceof ElementQueryInterface => (clone $value)->status(null)->all(),
                $value instanceof ElementInterface => [$value],
                default => [],
            };

            foreach ($nestedElements as $nested) {
                $this->_syncOwnerTree($nested);
            }
        }
    }

    private function _hasHyperField(ElementInterface $element): bool
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof HyperField) {
                return true;
            }
        }

        return false;
    }

    private function _getOwnerLinks(HyperField $field, ElementInterface $owner, ?LinkCollectionInterface $replacement): array
    {
        $links = [];
        $matched = false;

        foreach ($owner->getFieldLayout()?->getCustomFields() ?? [] as $occurrence) {
            if (!$occurrence instanceof HyperField || (int)$occurrence->id !== (int)$field->id) {
                continue;
            }

            $isReplacement = $field->layoutElement
                ? $occurrence->layoutElement?->uid === $field->layoutElement->uid
                : $occurrence->handle === $field->handle;
            $value = $replacement !== null && $isReplacement
                ? $replacement
                : $owner->getFieldValue($occurrence->handle);
            $matched = $matched || $isReplacement;

            if ($value instanceof LinkCollectionInterface) {
                $links = [...$links, ...$value->getLinks()];
            }
        }

        // Keep programmatic collections usable when the field has no layout occurrence.
        if (!$matched && $replacement !== null) {
            $links = [...$links, ...$replacement->getLinks()];
        }

        return $links;
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

    private function _relationRowKeys(array $rows): array
    {
        // Database drivers can return integers as strings, so compare normalized values in a fixed order.
        $keys = array_map(static fn(array $row): string => Json::encode([
            (int)$row['sortOrder'],
            (string)$row['linkTypeHandle'],
            $row['targetId'] !== null ? (int)$row['targetId'] : null,
            $row['targetSiteId'] !== null ? (int)$row['targetSiteId'] : null,
            $row['targetType'] !== null ? (string)$row['targetType'] : null,
        ]), $rows);

        sort($keys);

        return $keys;
    }

    private function _elementCacheKey(int $targetId, ?int $targetSiteId): string
    {
        return $targetId . ':' . ($targetSiteId ?? 'null');
    }
}
