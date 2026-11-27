<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\content\adapters\HyperFieldAdapter;
use verbb\hyper\content\Change;
use verbb\hyper\content\ContentRef;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\content\ModifyResult;
use verbb\hyper\content\RawContentMethods;
use verbb\hyper\content\RawJson;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Site as SiteLink;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkInstance;
use verbb\hyper\records\LinkRelation as LinkRelationRecord;
use verbb\hyper\services\LinkTypeConfigs;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\elements\db\ElementQuery;
use craft\events\DeleteSiteEvent;
use craft\events\MergeElementsEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

use yii\db\Expression;

use RuntimeException;
use Throwable;

class Content extends Component
{
    // Traits
    // =========================================================================

    use RawContentMethods;


    // Public Methods
    // =========================================================================

    /** Convert source payloads before destination field hydration. Use replace(null) to clear the field. */
    public function modifyRaw(HyperField $field, callable $transform, ?ModifyOptions $options = null): ModifyResult
    {
        $options ??= new ModifyOptions();
        $db = $options->db ?? Craft::$app->getDb();

        if ($options->syncRelations && $db !== Craft::$app->getDb()) {
            throw new RuntimeException('Relation synchronization requires the Craft database connection.');
        }

        return $db->transaction(function() use ($field, $transform, $options, $db): ModifyResult {
            $result = (new ElementContentStore($db))->eachFieldValue($field, function(ContentRef $ref) use ($transform): bool {
                $change = $transform($ref->value, $ref);

                if (!is_array($change) || !in_array($change['action'] ?? null, ['unchanged', 'replace'], true)
                    || ($change['action'] === 'replace' && !array_key_exists('value', $change))) {
                    throw new RuntimeException('modifyRaw requires Change::unchanged() or Change::replace($value); use replace(null) to clear.');
                }

                if ($change['action'] === 'unchanged' || $ref->value === $change['value']) {
                    return false;
                }

                $ref->value = $change['value'];

                return true;
            }, $options);

            if (!$options->dryRun && $options->syncRelations && $result->modified > 0) {
                $this->reconcileRelations($field, $options);
            }

            return $result;
        });
    }

    public function modify(
        HyperField $field,
        callable $transform,
        ?ModifyOptions $options = null,
    ): ModifyResult {
        $options ??= new ModifyOptions();
        $db = $options->db ?? Craft::$app->getDb();

        if ($options->syncRelations && $db !== Craft::$app->getDb()) {
            throw new RuntimeException('Relation synchronization requires the Craft database connection.');
        }

        return $db->transaction(function() use ($field, $transform, $options) {
            $adapter = new HyperFieldAdapter($field);
            $store = new ElementContentStore($options->db);

            $result = $store->eachFieldValue($field, function(ContentRef $ref, ModifyOptions $modifyOptions, ModifyResult $result) use ($adapter, $transform, $field, $options) {
                $decoded = $adapter->decode($ref->value, $ref);
                $existingEncoded = $adapter->encode($decoded, $ref);
                $newValue = $transform($decoded, $ref);

                if ($newValue === null) {
                    $newValue = new LinkCollection($field, []);
                }

                $encoded = $adapter->encode($newValue, $ref);

                if ($this->_serializedValuesEqual($existingEncoded, $encoded)
                    && !($options->persistTransformedValues && $newValue !== $decoded)) {
                    return false;
                }

                if (!$options->dryRun) {
                    $ref->value = $encoded;
                    $result->recordModification($ref, $newValue);
                }

                return true;
            }, $options);

            if (!$options->dryRun && $options->syncRelations) {
                foreach ($result->modifications as $modification) {
                    if ($modification['value'] instanceof LinkCollection) {
                        $this->_syncRelations($field, $modification['ref']);
                    }
                }
            }

            return $result;
        });
    }

    /**
     * Rebuild hyper_links from canonical owner JSON without changing content bytes.
     * Used to repair stale indexes after Content::modify sync failures (Astra A06).
     */
    public function reconcileRelations(HyperField $field, ?ModifyOptions $options = null): int
    {
        $options ??= new ModifyOptions();

        if (!$options->dryRun && $options->db && $options->db !== Craft::$app->getDb()) {
            throw new RuntimeException('Relation synchronization requires the Craft database connection.');
        }

        // Cleared values are visited too, so an emptied field drops its stale rows.
        $store = new ElementContentStore($options->db, includeClearedValues: true);
        $synced = 0;

        $store->eachFieldValue($field, function(ContentRef $ref) use ($field, $options, &$synced) {
            // The index writer reads every placement from the persisted owner.
            // Decoding this scan value would hydrate those links twice, and also
            // hydrate links belonging to derivatives and synthetic owners we skip.
            if (!$ref->hasDurableOwner) {
                return false;
            }

            $owner = $this->_resolveRelationOwner($ref, forReconciliation: true);

            if (!$owner) {
                return false;
            }

            if ($options->dryRun) {
                if (!$owner->trashed && !$owner->isProvisionalDraft && !ElementHelper::isDraftOrRevision($owner)
                    && $owner->getFieldLayout()?->getFieldById($field->id)) {
                    $synced++;
                }

                return false;
            }

            if ($this->_syncRelations($field, $ref, $owner)) {
                $synced++;
            }

            return false;
        }, $options);

        return $synced;
    }

    public function modifyLinkInstances(
        HyperField $field,
        callable $transform,
        ?ModifyOptions $options = null,
    ): ModifyResult {
        return $this->modify($field, function(LinkCollection $collection, ContentRef $ref) use ($field, $transform) {
            $links = $collection->getLinks();
            $changed = false;

            foreach ($links as $key => $link) {
                $instance = LinkInstance::fromLink($link, $field);
                $before = $instance->toSerialized();
                $newInstance = $transform($instance, $ref);

                if (!$newInstance instanceof LinkInstance) {
                    continue;
                }

                if ($newInstance->toSerialized() === $before) {
                    continue;
                }

                $newLink = Hyper::$plugin->getLinks()->createLinkFromInstance($field, $newInstance);

                if ($newLink) {
                    $links[$key] = $newLink;
                    $changed = true;
                }
            }

            return $changed ? $collection->withLinks($links) : $collection;
        }, $options);
    }

    public function pruneDeletedSite(DeleteSiteEvent $event): void
    {
        $this->pruneDeletedSiteUid($event->site->uid);
    }

    public function pruneDeletedSiteUid(string $siteUid): void
    {
        $this->_pruneHyperFieldSettings($siteUid);
        $this->_pruneLinkTypeConfigs($siteUid);
        $this->_pruneElementContent($siteUid);
    }

    public function handleAfterMergeElements(MergeElementsEvent $event): void
    {
        $mergedId = (int)$event->mergedElementId;
        $prevailingId = (int)$event->prevailingElementId;

        if (!$mergedId || !$prevailingId || $mergedId === $prevailingId) {
            return;
        }

        // Craft points relation fields at the prevailing element, on drafts, revisions and trashed elements too,
        // then fires this event before deleting the merged element. Do the same for Hyper's element links.
        $types = (new Query())->select(['type'])->from(Table::ELEMENTS)->where(['id' => [$mergedId, $prevailingId]])->indexBy('id')->column();
        $elementType = $types[$mergedId] ?? null;
        $fields = array_filter(Craft::$app->getFields()->getAllFields(false), static fn($field): bool => $field instanceof HyperField);

        // Each element link type targets one element type, so a link can only follow a merge within that type.
        if (!$elementType || $elementType !== ($types[$prevailingId] ?? null) || !$fields) {
            return;
        }

        // One scan finds every content row that could link to the merged element, so each field's own scan,
        // including values nested in Matrix, Neo, Vizy and other links, only reads those rows.
        $db = Craft::$app->getDb();
        $elementIds = (new Query())
            ->select(['elementId'])
            ->distinct()
            ->from(Table::ELEMENTS_SITES)
            ->where(['not', ['content' => null]])
            ->andWhere(['like', new Expression('CAST([[content]] AS ' . ($db->getDriverName() === 'pgsql' ? 'TEXT' : 'CHAR') . ')'), (string)$mergedId])
            ->column();

        foreach (array_chunk(array_map('intval', $elementIds), 500) as $chunk) {
            // Drafts are rewritten too, so rebase their conflict baselines on the rewritten canonical content.
            DraftConflicts::rebase($chunk, function() use ($fields, $chunk, $mergedId, $prevailingId, $elementType): void {
                foreach ($fields as $field) {
                    $this->modifyRaw($field, function(mixed $value) use ($field, $mergedId, $prevailingId, $elementType): array {
                        $merged = $this->_mergeElementLinks($field, $value, $mergedId, $prevailingId, $elementType);

                        return $merged === $value ? Change::unchanged() : Change::replace($merged);
                    }, new ModifyOptions(syncRelations: false, elementIds: $chunk));
                }
            });
        }

        // Content now matches, so move the reverse-relation index the same way Craft moves its relations.
        Db::update(LinkRelationRecord::tableName(), ['targetId' => $prevailingId], ['targetId' => $mergedId]);
    }


    // Private Methods
    // =========================================================================

    private function _mergeElementLinks(HyperField $field, mixed $value, int $mergedId, int $prevailingId, string $elementType): mixed
    {
        $rows = is_string($value) ? Json::decodeIfJson($value) : $value;

        if (!is_array($rows) || !array_is_list($rows)) {
            return $value;
        }

        $isMerged = static fn(mixed $id): bool => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id === $mergedId;
        $before = $rows;

        foreach ($rows as &$row) {
            if (!is_array($row) || !isset($row['linkValue'])) {
                continue;
            }

            $linkValue = $row['linkValue'];
            $ids = is_array($linkValue) ? $linkValue : [$linkValue];

            if (!array_filter($ids, $isMerged)) {
                continue;
            }

            // Resolve the link type as hydration does, including legacy handles, without hydrating the link.
            $handle = LinkInstance::fromSerialized($row, $field)->linkTypeHandle;
            $linkType = $handle !== '' ? $field->getLinkTypeByHandle($handle) : null;

            if (!$linkType instanceof ElementLink || !is_a($elementType, $linkType::elementType(), true)) {
                continue;
            }

            // Keep the stored shape (an ID list, or a legacy single ID) and its ID types.
            $ids = array_map(static fn(mixed $id): mixed => $isMerged($id) ? (is_string($id) ? (string)$prevailingId : $prevailingId) : $id, $ids);
            $row['linkValue'] = is_array($linkValue) ? array_values(array_unique($ids, SORT_REGULAR)) : $ids[0];
        }

        unset($row);

        if ($rows === $before) {
            return $value;
        }

        return is_string($value) ? RawJson::encode(RawJson::preserve($value, $rows)) : $rows;
    }

    private function _pruneHyperFieldSettings(string $siteUid): void
    {
        $fieldsService = Craft::$app->getFields();

        foreach ($fieldsService->getAllFields(false) as $field) {
            if (!$field instanceof HyperField) {
                continue;
            }

            // Shared-config fields — scrubbed via _pruneLinkTypeConfigs.
            if (!$field->hasCustomLinkTypes()) {
                continue;
            }

            $linkTypeConfigs = [];
            $changed = false;

            foreach ($field->getLinkTypes() as $linkType) {
                if ($linkType instanceof SiteLink && is_array($linkType->sites)) {
                    $filtered = array_values(array_filter(
                        $linkType->sites,
                        static fn(mixed $uid): bool => $uid !== $siteUid,
                    ));

                    if ($filtered !== $linkType->sites) {
                        $linkType->sites = $filtered;
                        $changed = true;
                    }
                }

                $linkTypeConfigs[] = $linkType->getSettingsConfigForDb();
            }

            if ($changed) {
                $field->setLinkTypes($linkTypeConfigs);
                $fieldsService->saveField($field);
            }
        }
    }

    private function _pruneLinkTypeConfigs(string $siteUid): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $all = $projectConfig->get(LinkTypeConfigs::PROJECT_CONFIG_PATH) ?? [];

        if (!is_array($all)) {
            return;
        }

        foreach ($all as $uid => $configData) {
            if (!is_array($configData)) {
                continue;
            }

            $linkTypes = ProjectConfigHelper::unpackAssociativeArrays($configData['linkTypes'] ?? []);
            [$linkTypes, $changed] = $this->_scrubSiteFromLinkTypeSettingsList($linkTypes, $siteUid);

            if (!$changed) {
                continue;
            }

            $configData['linkTypes'] = ProjectConfigHelper::packAssociativeArrays($linkTypes);

            $projectConfig->set(
                LinkTypeConfigs::PROJECT_CONFIG_PATH . '.' . $uid,
                $configData,
                'Prune deleted site from Hyper link type config',
            );
        }
    }

    private function _pruneElementContent(string $siteUid): void
    {
        $options = new ModifyOptions(
            syncRelations: false,
            contentContains: $siteUid,
        );

        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if (!$field instanceof HyperField) {
                continue;
            }

            if (!$this->_fieldHasSiteLinkType($field)) {
                continue;
            }

            $this->modifyLinkInstances($field, function(LinkInstance $instance) use ($siteUid, $field): LinkInstance {
                $linkType = $field->getLinkTypeByHandle($instance->linkTypeHandle);

                if (!$linkType instanceof SiteLink) {
                    return $instance;
                }

                if ($instance->linkValue !== $siteUid) {
                    return $instance;
                }

                $instance->linkValue = null;

                return $instance;
            }, $options);
        }
    }

    private function _fieldHasSiteLinkType(HyperField $field): bool
    {
        foreach ($field->getLinkTypes() as $linkType) {
            if ($linkType instanceof SiteLink) {
                return true;
            }
        }

        return false;
    }

    private function _scrubSiteFromLinkTypeSettingsList(array $linkTypes, string $siteUid): array
    {
        $changed = false;

        foreach ($linkTypes as $key => $linkType) {
            if (($linkType['type'] ?? '') !== SiteLink::class) {
                continue;
            }

            $sites = $linkType['sites'] ?? null;

            if (!is_array($sites)) {
                continue;
            }

            $filtered = array_values(array_filter(
                $sites,
                static fn(mixed $uid): bool => $uid !== $siteUid,
            ));

            if ($filtered === $sites) {
                continue;
            }

            $linkTypes[$key]['sites'] = $filtered;
            $changed = true;
        }

        return [$linkTypes, $changed];
    }

    private function _syncRelations(HyperField $field, ContentRef $ref, ?ElementInterface $owner = null): bool
    {
        if (!$ref->hasDurableOwner) {
            return false;
        }

        $owner ??= $this->_resolveRelationOwner($ref);

        if (!$owner) {
            throw new RuntimeException('Unable to resolve Hyper relation owner.');
        }

        // JSON-only nested nodes (notably Vizy) cannot share a parent owner's index key.
        if ($owner->trashed || $owner->isProvisionalDraft || ElementHelper::isDraftOrRevision($owner)
            || !$owner->getFieldLayout()?->getFieldById($field->id)) {
            return false;
        }

        // Content writes have completed; rebuild all occurrences from the persisted owner.
        if (!Hyper::$plugin->getLinkRelations()->syncFromLinkCollection($field, $owner)) {
            throw new RuntimeException('Unable to synchronize Hyper relations.');
        }

        return true;
    }

    /**
     * Resolve a stored content owner, excluding unavailable sites during index rebuilds.
     *
     * @param ContentRef $ref
     * @param bool $forReconciliation
     * @return ElementInterface|null
     */
    private function _resolveRelationOwner(ContentRef $ref, bool $forReconciliation = false): ?ElementInterface
    {
        $elementId = $ref->elementId;
        $siteId = $ref->siteId;

        // Deleted sites can retain content rows that Craft can no longer hydrate.
        // Disabled sites remain indexable, matching Craft's site-ID normalization.
        if ($forReconciliation && $siteId && Craft::$app->getSites()->getSiteById($siteId, true) === null) {
            return null;
        }

        // Nested Matrix/Neo owners are identified by block entry id in the JSON path.
        if (preg_match('/\.(\d+)\.fields\./', $ref->jsonPath, $matches)) {
            $elementId = (int)$matches[1];
        }

        $elements = Craft::$app->getElements();
        $elementRow = (new Query())
            ->select(['type', 'archived', 'dateDeleted', 'draftId', 'revisionId'])
            ->from('{{%elements}}')
            ->where(['id' => $elementId])
            ->one();
        $elementType = $elementRow['type'] ?? null;

        // Broken FK cascades can leave content without an element. Preserve the
        // orphaned content, but do not require an owner when rebuilding its index.
        if ($forReconciliation && $elementRow === null) {
            return null;
        }

        // Trash and derivatives never enter the relation index. Exclude them
        // before hydration, as their type-specific owner records may be gone.
        if ($forReconciliation && (!empty($elementRow['dateDeleted']) || !empty($elementRow['draftId']) || !empty($elementRow['revisionId']))) {
            return null;
        }

        // Removed plugins can leave durable content behind. Rebuilding the index
        // must preserve it without requiring that plugin's element class to load.
        if ($forReconciliation && $elementType !== null && !class_exists($elementType)) {
            return null;
        }

        // Include recoverable trash so callers can exclude its relations. Without
        // site context, retain standalone modify() callers' unconstrained lookup.
        // Craft still excludes archived elements when status is null. Match the
        // stored archive flag so valid content owners can always be reindexed.
        $owner = $elements->getElementById($elementId, $elementType, $siteId ?: null, [
            'trashed' => null,
            'archived' => (bool)($elementRow['archived'] ?? false),
        ]);

        // Craft's garbage collection deletes elements whose type-specific record
        // is missing, so no owner can load. Preserve their content, unindexed.
        if (!$owner && $forReconciliation && $this->_isPartialElement($elementId, $elementType)) {
            return null;
        }

        if (!$owner) {
            // Identify the stored owner without including authored link content.
            throw new RuntimeException(sprintf(
                'Unable to resolve Hyper relation owner. Element ID: %d; site ID: %d; type: %s; content row ID: %d; path: %s. Diagnostics: %s',
                $elementId,
                $siteId,
                $elementType ?? 'unknown',
                $ref->rowId,
                $ref->jsonPath,
                $this->_relationOwnerDiagnostics($elementId, $elementType, $elementRow),
            ));
        }

        return $owner;
    }

    private function _isPartialElement(int $elementId, ?string $elementType): bool
    {
        // Match the element types and records checked by Gc::deletePartialElements().
        $table = match ($elementType) {
            Address::class => Table::ADDRESSES,
            Asset::class => Table::ASSETS,
            Category::class => Table::CATEGORIES,
            ContentBlock::class => Table::CONTENTBLOCKS,
            Entry::class => Table::ENTRIES,
            GlobalSet::class => Table::GLOBALSETS,
            Tag::class => Table::TAGS,
            User::class => Table::USERS,
            default => null,
        };

        try {
            // Plugins register their own element types with garbage collection, such as
            // Neo blocks left without a block record after a draft is applied.
            $tables = $table !== null ? [$table] : $this->_elementRecordTables($elementType);

            foreach ($tables as $recordTable) {
                if (!(new Query())->from($recordTable)->where(['id' => $elementId])->exists()) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Report the unresolved owner, with its diagnostics, rather than this query failure.
        }

        return false;
    }

    /**
     * Return the type-specific record tables an element type's query requires.
     *
     * @param string|null $elementType
     * @return string[]
     */
    private function _elementRecordTables(?string $elementType): array
    {
        if ($elementType === null || !is_subclass_of($elementType, ElementInterface::class)) {
            return [];
        }

        $query = $elementType::find();

        if (!$query instanceof ElementQuery) {
            return [];
        }

        // ElementQuery::joinElementTable() inner-joins each record table on the element ID.
        // Only this exact join is a type-specific record; other joins may be optional.
        $tables = [];

        foreach ($query->prepareSubquery()->join ?? [] as $join) {
            $table = $join[1] ?? null;
            $alias = is_array($table) && count($table) === 1 ? array_key_first($table) : null;

            if (is_string($alias) && ($join[0] ?? null) === 'INNER JOIN' && ($join[2] ?? null) === "[[$alias.id]] = [[elements.id]]") {
                $tables[] = $table[$alias];
            }
        }

        return $tables;
    }

    private function _relationOwnerDiagnostics(int $elementId, ?string $elementType, ?array $elementRow): string
    {
        $diagnostics = ['element' => ['exists' => $elementRow !== null] + ($elementRow ?? [])];

        // Read only allowlisted metadata after a failed lookup. Hydrating the
        // owner again would hide the same broken records we need to identify.
        try {
            if ($elementType !== null && is_a($elementType, Entry::class, true)) {
                $entry = (new Query())
                    ->select(['sectionId', 'typeId', 'fieldId', 'primaryOwnerId'])
                    ->from('{{%entries}}')
                    ->where(['id' => $elementId])
                    ->one();
                $diagnostics['entry'] = ['exists' => $entry !== null] + ($entry ?? []);

                if (!empty($entry['primaryOwnerId'])) {
                    $primaryOwner = (new Query())
                        ->select(['id', 'type', 'archived', 'dateDeleted', 'draftId', 'revisionId'])
                        ->from('{{%elements}}')
                        ->where(['id' => $entry['primaryOwnerId']])
                        ->one();
                    $diagnostics['primaryOwner'] = ['exists' => $primaryOwner !== null] + ($primaryOwner ?? []);
                    $diagnostics['primaryOwnerMappingExists'] = (new Query())
                        ->from('{{%elements_owners}}')
                        ->where(['elementId' => $elementId, 'ownerId' => $entry['primaryOwnerId']])
                        ->exists();
                }
            }
        } catch (Throwable $exception) {
            // Diagnostics must not replace the original failure or expose SQL,
            // connection details or authored values in a secondary exception.
            $diagnostics['collectionError'] = $exception::class;
        }

        return Json::encode($diagnostics);
    }

    private function _serializedValuesEqual(mixed $left, mixed $right): bool
    {
        return Json::encode($left) === Json::encode($right);
    }
}
