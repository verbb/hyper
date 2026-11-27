<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\StringHelper;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\errors\InvalidElementException;
use craft\events\DraftEvent;
use craft\events\ElementEvent;
use craft\helpers\Json;

class DraftConflicts
{
    // Static Methods
    // =========================================================================

    public static function created(DraftEvent $event): void
    {
        $draft = $event->draft;

        if (!$draft || $draft->getIsCanonical()) {
            return;
        }

        foreach (self::_localizedDrafts($draft) as $localized) {
            foreach (self::_fields($localized) as $field) {
                $canonical = $localized->siteId == $event->canonical->siteId ? $event->canonical : $localized->getCanonical();
                self::_store($field, $localized, self::_hash($field, $canonical));
            }
        }
    }

    public static function merged(ElementEvent $event): void
    {
        $draft = $event->element;

        // Matrix children edited through an owner's draft merge as derivatives, not drafts.
        if (!$draft->getIsDraft() && !($draft instanceof NestedElementInterface && $draft->getIsDerivative())) {
            return;
        }

        // Most merged nested elements have no Hyper field, so skip loading their other sites.
        if (!self::_fields($draft)) {
            return;
        }

        foreach (self::_localizedDrafts($draft) as $localized) {
            foreach (self::_fields($localized) as $field) {
                if (!$localized->isFieldModified($field->handle)) {
                    self::_store($field, $localized, self::_hash($field, $localized));
                }
            }
        }
    }

    public static function inputToken(HyperField $field, ?ElementInterface $owner): ?string
    {
        if (!$owner?->id || !$owner::hasDrafts() || $owner instanceof LinkInterface || $owner->getIsRevision() || !$field->layoutElement?->uid) {
            return null;
        }

        $baseline = $owner->getIsDraft() && !$owner->getIsCanonical()
            ? (self::_row($field, $owner)['baseline'] ?? null)
            : (self::_submittedBaseline($field, $owner)['baseline'] ?? self::_hash($field, $owner));

        return Craft::$app->getSecurity()->hashData(Json::encode([
            'owner' => $owner->getCanonicalId(),
            'site' => $owner->siteId,
            'field' => $field->layoutElement->uid,
            'baseline' => $baseline,
        ]));
    }

    public static function rememberEdit(HyperField $field, ElementInterface $draft): void
    {
        if (!$draft->id || !$draft->getIsDraft() || $draft->getIsCanonical() || $draft->propagating || $draft->mergingCanonicalChanges || !$draft->isFieldDirty($field->handle)) {
            return;
        }

        $row = self::_row($field, $draft);

        if (!$row || $row['edited']) {
            return;
        }

        // The editor can predate the provisional draft. Its signed snapshot is the
        // baseline for the first edit; later autosaves must never replace it.
        $submitted = self::_submittedBaseline($field, $draft);
        $baseline = $submitted !== null ? $submitted['baseline'] : $row['baseline'];

        Craft::$app->getDb()->createCommand()->update('{{%hyper_draft_baselines}}', ['baseline' => $baseline, 'edited' => true], self::_key($field, $draft))->execute();
    }

    public static function validateCanonicalSave(HyperField $field, ElementInterface $element): bool
    {
        // Craft publishes a Matrix child edited through its owner's draft by copying the child's derivative
        // over it, without an apply-draft event. Check that copy against the baseline of the child's draft.
        if ($element->updatingFromDerivative && $element->duplicateOf instanceof NestedElementInterface) {
            return self::_validateDerivativeUpdate($field, $element, $element->duplicateOf);
        }

        $request = Craft::$app->getRequest();

        if (!$element->id || !$element::hasDrafts() || !$element->getIsCanonical() || $element->getIsDraft() || $element->getIsRevision() || $element instanceof LinkInterface || $element->propagating || $element->duplicateOf || $request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return true;
        }

        // New nested elements can be canonical while privately owned by a draft.
        // Their own autosaves are not concurrent published edits. Follow primary
        // ownership so a published child merely viewed through a draft stays protected.
        $primaryOwner = $element;

        while ($primaryOwner instanceof NestedElementInterface && ($primaryOwner = $primaryOwner->getPrimaryOwner())) {
            if (!$primaryOwner->getIsCanonical() || $primaryOwner->getIsDraft() || $primaryOwner->getIsRevision()) {
                return true;
            }
        }

        $submitted = self::_submittedBaseline($field, $element);

        if ($submitted === null) {
            return true;
        }

        $current = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->trashed(null)->ignorePlaceholders()->one();

        if (!$current || !self::_hasField($field, $current)) {
            return true;
        }

        $publishedHash = self::_hash($field, $current);
        $proposedHash = self::_hash($field, $element);

        if ($publishedHash === $proposedHash || self::_matchesBaseline($submitted['baseline'], $field, $current, $publishedHash) || self::_matchesLegacyBaseline($submitted['baseline'], $field, $current)) {
            return true;
        }

        if (self::_matchesBaseline($submitted['baseline'], $field, $element, $proposedHash)) {
            $field->copyValue($current, $element);

            return true;
        }

        // Clicking Save before autosave completes uses Craft's canonical-save path.
        // Preserve all submitted edits in a provisional draft before rejecting it.
        $user = Craft::$app->getUser()->getIdentity();
        $draft = $element::find()->draftOf($element->id)->provisionalDrafts(true)->draftCreator($user)->siteId($element->siteId)->status(null)->one();
        $draft ??= Craft::$app->getDrafts()->createDraft($current, $user->id, provisional: true);

        $postedAttributes = array_intersect(array_keys($request->getBodyParams()), $element->safeAttributes(), $element->attributes());

        foreach (array_unique([...$element->getDirtyAttributes(), ...$postedAttributes]) as $attribute) {
            if ($element->$attribute != $current->$attribute) {
                $draft->$attribute = $element->$attribute;
            }
        }

        if ($request->getBodyParam('enabledForSite') !== null) {
            $draft->setEnabledForSite($element->getEnabledForSite());
        }

        foreach ($element->getDirtyFields() as $handle) {
            $element->getFieldLayout()->getFieldByHandle($handle)?->copyValue($element, $draft);
        }

        if (!Craft::$app->getElements()->saveElement($draft, false)) {
            throw new InvalidElementException($draft);
        }

        $element->addError($field->handle, Craft::t('hyper', 'This field changed in the current entry while you were editing. Your draft is saved. Copy your changes, discard this draft, then reopen the current entry and reapply your changes.'));

        return false;
    }

    public static function apply(DraftEvent $event): void
    {
        $draft = $event->draft;

        if (!$draft || $draft->getIsCanonical()) {
            return;
        }

        $conflict = false;
        $copies = [];

        foreach (self::_localizedDrafts($draft) as $localized) {
            // Use the actual applying instance for the current site, including unsaved values.
            if ($localized->siteId == $draft->siteId) {
                $localized = $draft;
            }

            $canonical = $localized::find()->id($draft->getCanonicalId())->siteId($localized->siteId)->status(null)->trashed(null)->ignorePlaceholders()->one();

            if (!$canonical) {
                continue;
            }

            foreach (self::_fields($localized) as $field) {
                if (!self::_hasField($field, $canonical)) {
                    continue;
                }

                $row = self::_row($field, $localized);
                $baseline = $row['baseline'] ?? null;
                $current = self::_hash($field, $canonical);
                $proposed = self::_hash($field, $localized);

                // A field added to the layout after the draft was created has no baseline. If the current
                // entry has never stored it either, the draft started from the same empty value.
                // Read the current entry through its own layout, which can differ when the draft changes entry type.
                $canonicalField = $canonical->getFieldLayout()?->getFieldByHandle($field->handle);

                if (!$row && $canonicalField instanceof HyperField && self::_storedValue($canonicalField, $canonical) === null) {
                    $baseline = $current;
                }

                if ($current === $proposed || self::_matchesBaseline($baseline, $field, $canonical, $current) || self::_matchesLegacyBaseline($baseline, $field, $canonical)) {
                    continue;
                }

                // A reverted local edit must not roll back newer published content.
                if (self::_matchesBaseline($baseline, $field, $localized, $proposed) || ($baseline === null && !$localized->isFieldModified($field->handle))) {
                    $copies[] = [$field, $canonical, $localized];

                    continue;
                }

                $message = $baseline === null
                    ? 'The original version of this field could not be verified. Your draft is saved. Copy your changes, discard this draft, then reopen the current entry and reapply your changes.'
                    : 'This field changed in the current entry while you were editing. Your draft is saved. Copy your changes, discard this draft, then reopen the current entry and reapply your changes.';
                $message = Craft::t('hyper', $message);

                if (!in_array($message, $draft->getErrors($field->handle), true)) {
                    $draft->addError($field->handle, $message);
                }

                $conflict = true;
            }
        }

        if ($conflict) {
            throw new InvalidElementException($draft);
        }

        // Craft's timestamp tracking has one-second precision. Copy untouched
        // fields explicitly, including sites that publishing will duplicate later.
        Craft::$app->getDb()->transaction(function() use ($copies, $draft) {
            $otherSites = [];

            foreach ($copies as [$field, $canonical, $localized]) {
                $field->copyValue($canonical, $localized);
                $localized->setCanonical($canonical);

                if ($localized !== $draft) {
                    $otherSites[$localized->siteId] = $localized;
                }
            }

            foreach ($otherSites as $localized) {
                $localized->mergingCanonicalChanges = true;

                try {
                    if (!Craft::$app->getElements()->saveElement($localized, false, false)) {
                        throw new InvalidElementException($localized);
                    }
                } finally {
                    $localized->mergingCanonicalChanges = false;
                }
            }
        });
    }

    private static function _validateDerivativeUpdate(HyperField $field, ElementInterface $element, ElementInterface $derivative): bool
    {
        // Drafts are checked when they're applied, and a child without a stored baseline can't be verified.
        if ($derivative->getIsCanonical() || $derivative->getIsDraft() || $derivative->getIsRevision() || !$row = self::_row($field, $derivative)) {
            return true;
        }

        $current = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->trashed(null)->ignorePlaceholders()->one();

        if (!$current || !self::_hasField($field, $current)) {
            return true;
        }

        $publishedHash = self::_hash($field, $current);
        $proposedHash = self::_hash($field, $element);

        if ($publishedHash === $proposedHash || self::_matchesBaseline($row['baseline'], $field, $current, $publishedHash) || self::_matchesLegacyBaseline($row['baseline'], $field, $current)) {
            return true;
        }

        // A child edit that left this field alone must not roll back newer published content.
        if (self::_matchesBaseline($row['baseline'], $field, $element, $proposedHash) || !$derivative->isFieldModified($field->handle)) {
            $field->copyValue($current, $element);

            return true;
        }

        $element->addError($field->handle, Craft::t('hyper', 'This field changed in the current entry while you were editing. Your draft is saved. Copy your changes, discard this draft, then reopen the current entry and reapply your changes.'));

        return false;
    }

    private static function _localizedDrafts(ElementInterface $draft): array
    {
        return $draft::find()->id($draft->id)->site('*')->drafts($draft->getIsDraft())->provisionalDrafts(null)->status(null)->all();
    }

    private static function _fields(ElementInterface $element): array
    {
        return array_filter($element->getFieldLayout()?->getCustomFields() ?? [], static fn($field) => $field instanceof HyperField && $field->layoutElement?->uid);
    }

    private static function _hasField(HyperField $field, ElementInterface $element): bool
    {
        // A changed entry type can add a field the current entry has no value for,
        // so there is nothing for an edit to conflict with.
        return $element->getFieldLayout()?->getFieldByHandle($field->handle)?->id === $field->id;
    }

    private static function _key(HyperField $field, ElementInterface $draft): array
    {
        return ['draftId' => $draft->id, 'siteId' => $draft->siteId, 'layoutUid' => $field->layoutElement->uid];
    }

    private static function _row(HyperField $field, ElementInterface $draft): ?array
    {
        return (new Query())->from('{{%hyper_draft_baselines}}')->where(self::_key($field, $draft))->one() ?: null;
    }

    private static function _store(HyperField $field, ElementInterface $draft, string $baseline): void
    {
        Craft::$app->getDb()->createCommand()->upsert('{{%hyper_draft_baselines}}', [
            ...self::_key($field, $draft), 'fieldId' => $field->id, 'baseline' => $baseline, 'edited' => false,
        ], ['baseline' => $baseline, 'edited' => false])->execute();
    }

    private static function _matchesBaseline(?string $baseline, HyperField $field, ElementInterface $element, string $hash): bool
    {
        // Existing drafts and open editors may still carry the original fingerprint.
        return $baseline === $hash || ($baseline !== null && $baseline === self::_hash($field, $element, true));
    }

    private static function _matchesLegacyBaseline(?string $baseline, HyperField $field, ElementInterface $current): bool
    {
        // Earlier releases fingerprinted some saved links differently. 3.0.0–3.0.7 hydrated links of
        // disabled types as unsupported, and releases up to 3.1.0 resolved Hyper 1/2 `default-` handles
        // to a custom type sorted above the built-in type of its class. Compare the stored current content
        // each of those ways, so drafts started before updating don't report a false conflict.
        if ($baseline === null || !$current->id) {
            return false;
        }

        $links = Hyper::$plugin->getLinks();

        // Releases before 3.2.0 also fingerprinted cleared links, which saving now drops.
        if ($links->withClearedLinksKept(static fn(): bool => $baseline === self::_hash($field, $current) || $baseline === self::_hash($field, $current, true))) {
            return true;
        }

        $hasDisabledType = (bool)array_filter($field->getLinkTypes(), static fn(LinkInterface $type): bool => !$type->enabled);
        $hasCustomTypeFirst = self::_hasCustomTypeFirst($field);

        if (!$hasDisabledType && !$hasCustomTypeFirst) {
            return false;
        }

        $stored = self::_storedValue($field, $current);

        if ($stored === null) {
            return false;
        }

        $exact = static fn(): bool => $baseline === self::_hash($field, $current, false, $stored) || $baseline === self::_hash($field, $current, true, $stored);
        $matches = static fn(): bool => $exact() || $links->withClearedLinksKept($exact);
        $disabledUnsupported = static fn(): bool => $links->withDisabledTypesUnsupported($matches);

        if ($hasDisabledType && $disabledUnsupported()) {
            return true;
        }

        if (!$hasCustomTypeFirst) {
            return false;
        }

        // 3.0.0–3.0.7 combined both, and 3.1.0 resolved the handles alone.
        return $links->withFirstOfClassLegacyHandles($matches) || ($hasDisabledType && $links->withFirstOfClassLegacyHandles($disabledUnsupported));
    }

    private static function _storedValue(HyperField $field, ElementInterface $element): mixed
    {
        $content = (new Query())
            ->select(['content'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $element->id, 'siteId' => $element->siteId])
            ->scalar();
        $content = is_string($content) ? Json::decodeIfJson($content) : $content;

        return is_array($content) ? ($content[$field->layoutElement->uid] ?? null) : null;
    }

    private static function _hasCustomTypeFirst(HyperField $field): bool
    {
        // Old and current resolution of a Hyper 1/2 handle differ only when a custom type sorts above the built-in type of its class.
        $linkTypes = $field->getLinkTypes();

        foreach ($linkTypes as $linkType) {
            $legacyHandle = 'default-' . StringHelper::toKebabCase($linkType::class);

            if (LinkTypeConfigs::findLegacyStockLinkType($linkTypes, $legacyHandle) !== LinkTypeConfigs::findLegacyStockLinkType($linkTypes, $legacyHandle, true)) {
                return true;
            }
        }

        return false;
    }

    private static function _hash(HyperField $field, ElementInterface $element, bool $includeIdentity = false, mixed $storedValue = null): string
    {
        $links = $field->normalizeValue($storedValue ?? $element->getFieldValue($field->handle), $element);
        $value = $field->serializeValueForDb($links, $element);

        if (!$includeIdentity) {
            $dropped = count($value) !== count($links->getLinks());
            $index = -1;

            foreach (array_values($links->getLinks()) as $link) {
                // Saving drops cleared links. When it has, pair each stored value with its own link.
                if ($dropped && ($value[$index + 1]['uid'] ?? null) !== $link->uid) {
                    continue;
                }

                $index++;

                // Legacy IDs are random on each read, then become ordinary IDs after
                // a browser round trip or save. Compare content independently of both.
                unset($value[$index]['uid']);

                if (!$link instanceof ElementInterface) {
                    continue;
                }

                foreach ($link->getFieldLayout()?->getCustomFieldElements() ?? [] as $placement) {
                    $nestedField = $placement->getField();

                    if ($nestedField instanceof HyperField) {
                        // Only normalize known Hyper values; other custom fields may
                        // have meaningful UID properties that must remain protected.
                        $value[$index]['fields'][$placement->uid] = self::_hash($nestedField, $link);
                    }
                }
            }
        }

        return hash('sha256', Json::encode(self::_sortKeys($value)));
    }

    private static function _sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::_sortKeys(...), $value);
    }

    private static function _submittedBaseline(HyperField $field, ElementInterface $owner): ?array
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$field->layoutElement?->uid) {
            return null;
        }

        foreach (self::_tokens($request->getBodyParams()) as $token) {
            $decoded = Craft::$app->getSecurity()->validateData($token);

            if ($decoded === false) {
                continue;
            }

            $data = Json::decode($decoded);

            if (($data['owner'] ?? null) == $owner->getCanonicalId() && ($data['site'] ?? null) == $owner->siteId && ($data['field'] ?? null) === $field->layoutElement->uid && array_key_exists('baseline', $data)) {
                return $data;
            }
        }

        return null;
    }

    private static function _tokens(array $params): array
    {
        $tokens = [];

        foreach ($params as $key => $value) {
            if (is_string($key) && str_ends_with($key, '__hyperDraftBaseline') && is_string($value)) {
                $tokens[] = $value;
            } elseif (is_array($value)) {
                array_push($tokens, ...self::_tokens($value));
            }
        }

        return $tokens;
    }
}
