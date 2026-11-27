<?php
namespace verbb\hyper\helpers;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollectionInterface;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use craft\helpers\Json;

use yii\queue\ExecEvent;
use yii\queue\Queue;
use yii\web\ForbiddenHttpException;

class CpInputContext
{
    // Static Properties
    // =========================================================================

    private static array $_queueJobs = [];


    // Static Methods
    // =========================================================================

    /**
     * Whether saves made now belong to the CP user's own request, so editor checks apply. Craft's web
     * runner executes queue jobs inside whichever CP request triggered it, as that user or a guest.
     * A job's saves aren't that user's input, so they skip the checks, as they do on the console.
     *
     * @internal
     */
    public static function isEditorRequest(): bool
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return false;
        }

        foreach (self::$_queueJobs as $event) {
            // A job another handler skips before it runs gets no after-event to remove it.
            if (!$event->handled) {
                return false;
            }
        }

        return true;
    }

    /**
     * @internal Registered for queue execution events.
     */
    public static function trackQueueJob(ExecEvent $event): void
    {
        if ($event->name === Queue::EVENT_BEFORE_EXEC) {
            self::$_queueJobs[] = $event;
        } else {
            self::$_queueJobs = array_values(array_filter(self::$_queueJobs, static fn(ExecEvent $job): bool => $job !== $event && !$job->handled));
        }
    }

    /**
     * @internal Registered for the end of a queue run.
     */
    public static function resetQueueJobs(): void
    {
        self::$_queueJobs = [];
    }

    public static function create(HyperField $field, ?ElementInterface $owner): ?string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || !Craft::$app->getUser()->getId()) {
            return null;
        }

        // Unsaved/nested inputs need proof that this field was rendered in an authorized
        // editor. A caller cannot obtain that proof by omitting or swapping an owner ID.
        return Craft::$app->getSecurity()->hashData(Json::encode([
            'userId' => Craft::$app->getUser()->getId(),
            'fieldId' => (int)$field->id,
            'siteId' => (int)($owner?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id),
            'ownerId' => $owner instanceof LinkInterface ? null : $owner?->id,
            'expires' => time() + 86400,
        ]));
    }

    /**
     * Rejects selections the current user can't view. `$isStored` lets a save keep selections the
     * owner already stores, as Craft's relation fields keep existing selections, so only added or changed ones are checked.
     */
    public static function assertVisibleSelections(ElementInterface $element, ?callable $isStored = null): void
    {
        if (!self::isEditorRequest()) {
            return;
        }

        self::_walkSelections($element, static function(ElementInterface $selected) use ($isStored): void {
            if (!Craft::$app->getElements()->canView($selected) && !($isStored && $isStored($selected))) {
                throw new ForbiddenHttpException('User is not authorized to view a selected element.');
            }
        });
    }

    /**
     * Returns a matcher for selections the owner's field already stores. Stored content is only
     * read when a selection fails the view check, so ordinary saves skip the extra queries.
     */
    public static function storedSelections(HyperField $field, ElementInterface $owner): callable
    {
        $keys = null;

        return static function(ElementInterface $selected) use (&$keys, $field, $owner): bool {
            $keys ??= self::_storedSelectionKeys($field, $owner);
            $key = self::_selectionKey($selected);

            return $key !== null && isset($keys[$key]);
        };
    }

    public static function validate(string $token, int $fieldId, int $siteId, ?int $ownerId): array
    {
        $raw = Craft::$app->getSecurity()->validateData($token);
        $data = $raw === false ? null : Json::decodeIfJson($raw);

        if (!is_array($data)
            || ($data['userId'] ?? null) !== Craft::$app->getUser()->getId()
            || ($data['fieldId'] ?? null) !== $fieldId
            || ($data['siteId'] ?? null) !== $siteId
            || ($data['expires'] ?? 0) < time()
            || ($ownerId && $ownerId !== ($data['ownerId'] ?? null))) {
            throw new ForbiddenHttpException('Invalid or expired Hyper input context. Reload the editor.');
        }

        return $data;
    }

    private static function _walkSelections(ElementInterface $element, callable $visit): void
    {
        if ($element instanceof ElementLink) {
            foreach ($element->getElements() as $selected) {
                $visit($selected);
            }
        }

        // Posted clipboard content can contain relation fields as well as the main link.
        // Rendering a native chip does not itself enforce canView on its supplied element.
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $nestedQuery = $field instanceof Matrix || (class_exists(\benf\neo\Field::class) && $field instanceof \benf\neo\Field);

            if ($field instanceof BaseRelationField || $nestedQuery) {
                $related = $element->getFieldValue($field->handle);

                if ($field instanceof BaseRelationField && $related instanceof ElementQueryInterface) {
                    $related = (clone $related)
                        ->drafts(null)
                        ->status(null)
                        ->limit(null)
                        ->unique()
                        ->eagerly(false);
                }

                foreach ($related->all() as $selected) {
                    if (!$nestedQuery || $selected->id) {
                        $visit($selected);
                    }

                    if ($nestedQuery) {
                        self::_walkSelections($selected, $visit);
                    }
                }
            } elseif ($field instanceof ContentBlock) {
                $block = $element->getFieldValue($field->handle);

                if ($block instanceof ElementInterface) {
                    if ($block->id) {
                        $visit($block);
                    }

                    self::_walkSelections($block, $visit);
                }
            } elseif ($field instanceof HyperField) {
                $links = $element->getFieldValue($field->handle);

                if ($links instanceof LinkCollectionInterface) {
                    foreach ($links->getLinks() as $link) {
                        self::_walkSelections($link, $visit);
                    }
                }
            } elseif (class_exists(\verbb\vizy\fields\VizyField::class) && $field instanceof \verbb\vizy\fields\VizyField) {
                $nodes = $element->getFieldValue($field->handle);

                if ($nodes instanceof \verbb\vizy\models\NodeCollection) {
                    self::_walkVizySelections($nodes->getNodes(), $element, $visit);
                }
            }
        }
    }

    private static function _walkVizySelections(array $nodes, ElementInterface $owner, callable $visit): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof \verbb\vizy\nodes\VizyBlock) {
                $block = $node->getBlockElement($owner);

                foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                    $block->setFieldValue($field->handle, $node->getFieldValue($field->handle));
                }

                self::_walkSelections($block, $visit);
            }

            self::_walkVizySelections($node->getContent(), $owner, $visit);
        }
    }

    private static function _storedSelectionKeys(HyperField $field, ElementInterface $owner): array
    {
        if ($owner instanceof LinkInterface) {
            return [];
        }

        // Only content already in the database counts, re-read by ID so posted values can't add to it:
        // the owner itself, the canonical element of a draft, and the element Craft is duplicating
        // (which is also the draft when one is applied). Keys include the site, so re-siting a
        // stored target is still a new selection.
        $sources = [];

        if ($owner->id) {
            $sources[] = [$owner::class, $owner->id, $owner->siteId];
        }

        if ($owner->getIsDerivative()) {
            $sources[] = [$owner::class, $owner->getCanonicalId(), $owner->siteId];
        }

        if ($owner->duplicateOf?->id) {
            $sources[] = [$owner->duplicateOf::class, $owner->duplicateOf->id, $owner->duplicateOf->siteId];
        }

        $keys = [];

        foreach ($sources as [$class, $id, $siteId]) {
            $stored = Craft::$app->getElements()->getElementById($id, $class, $siteId);

            // Match by ID, since a changed entry type can reuse the handle for a different field.
            if (!$stored || $stored->getFieldLayout()?->getFieldByHandle($field->handle)?->id !== $field->id) {
                continue;
            }

            $links = $stored->getFieldValue($field->handle);

            if (!$links instanceof LinkCollectionInterface) {
                continue;
            }

            foreach ($links->getLinks() as $link) {
                self::_walkSelections($link, static function(ElementInterface $selected) use (&$keys): void {
                    $key = self::_selectionKey($selected);

                    if ($key !== null) {
                        $keys[$key] = true;
                    }
                });
            }
        }

        return $keys;
    }

    private static function _selectionKey(ElementInterface $element): ?string
    {
        if (!$element->id) {
            return null;
        }

        return $element::class . ':' . $element->id . ':' . $element->siteId;
    }
}
