<?php
namespace verbb\hyper\services;

use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
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

        if (!$draft->getIsDraft()) {
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
        $request = Craft::$app->getRequest();

        if (!$element->id || !$element::hasDrafts() || !$element->getIsCanonical() || $element->getIsDraft() || $element->getIsRevision() || $element instanceof LinkInterface || $element->propagating || $element->duplicateOf || $request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return true;
        }

        $submitted = self::_submittedBaseline($field, $element);

        if ($submitted === null) {
            return true;
        }

        $current = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->trashed(null)->ignorePlaceholders()->one();

        if (!$current) {
            return true;
        }

        $publishedHash = self::_hash($field, $current);
        $proposedHash = self::_hash($field, $element);

        if ($publishedHash === $proposedHash || $submitted['baseline'] === $publishedHash) {
            return true;
        }

        if ($submitted['baseline'] === $proposedHash) {
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
                $baseline = self::_row($field, $localized)['baseline'] ?? null;
                $current = self::_hash($field, $canonical);
                $proposed = self::_hash($field, $localized);

                if ($current === $proposed || $baseline === $current) {
                    continue;
                }

                // A reverted local edit must not roll back newer published content.
                if ($baseline === $proposed || ($baseline === null && !$localized->isFieldModified($field->handle))) {
                    $copies[] = [$field, $canonical, $localized];
                    continue;
                }

                $message = $baseline === null
                    ? 'The original version of this field could not be verified. Your draft is saved. Copy your changes, discard this draft, then reopen the current entry and reapply your changes.'
                    : 'This field changed in the current entry while you were editing. Your draft is saved. Copy your changes, discard this draft, then reopen the current entry and reapply your changes.';
                $draft->addError($field->handle, Craft::t('hyper', $message));
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

    private static function _localizedDrafts(ElementInterface $draft): array
    {
        return $draft::find()->id($draft->id)->site('*')->drafts(true)->provisionalDrafts(null)->status(null)->all();
    }

    private static function _fields(ElementInterface $element): array
    {
        return array_filter($element->getFieldLayout()?->getCustomFields() ?? [], static fn($field) => $field instanceof HyperField && $field->layoutElement?->uid);
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

    private static function _hash(HyperField $field, ElementInterface $element): string
    {
        $value = $field->serializeValueForDb($element->getFieldValue($field->handle), $element);
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
