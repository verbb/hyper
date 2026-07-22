<?php
namespace verbb\hyper\services;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkCollectionInterface;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;

class MultisiteLinks extends Component
{
    // Properties
    // =========================================================================

    private array $_propagating = [];


    // Public Methods
    // =========================================================================

    public function resolveElement(
        string $elementType,
        int $referenceId,
        ?int $siteId,
        mixed $status,
        ?callable $modifyQuery = null,
    ): ?ElementInterface {
        if (!$referenceId) {
            return null;
        }

        $query = $elementType::find()
            ->id($referenceId)
            ->status($status);

        if ($siteId) {
            $query->siteId($siteId);
        }

        if ($modifyQuery) {
            $modifyQuery($query, $status);
        }

        if ($element = $query->one()) {
            return $element;
        }

        if (!$siteId) {
            return null;
        }

        $reference = $elementType::find()
            ->id($referenceId)
            ->site('*')
            ->status(null)
            ->one();

        if (!$reference) {
            return null;
        }

        $localizedQuery = $elementType::find()
            ->siteId($siteId)
            ->status($status)
            ->where(['elements.canonicalId' => $reference->getCanonicalId()]);

        if ($modifyQuery) {
            $modifyQuery($localizedQuery, $status);
        }

        return $localizedQuery->one();
    }

    public function shouldLocalizePropagatedValue(HyperField $field, ElementInterface $element): bool
    {
        if (
            $element->propagating &&
            ($element->propagateAll || ($element->isNewForSite && !isset($element->duplicateOf))) &&
            isset($element->propagatingFrom) &&
            $field->getTranslationKey($element) !== $field->getTranslationKey($element->propagatingFrom)
        ) {
            return true;
        }

        if (
            !$element->propagating &&
            isset($element->duplicateOf) &&
            ($element->propagateAll || $element->isNewForSite) &&
            $field->getTranslationKey($element) !== $field->getTranslationKey($element->duplicateOf)
        ) {
            return true;
        }

        return false;
    }

    public function localizeLinkCollection(
        HyperField $field,
        LinkCollectionInterface $collection,
        ElementInterface $element,
        ?int $sourceSiteId = null,
    ): LinkCollection {
        $sourceSiteId ??= $element->propagatingFrom->siteId ?? $element->duplicateOf->siteId ?? $element->siteId;
        $targetSiteId = $element->siteId;

        if ($sourceSiteId === $targetSiteId) {
            return $collection;
        }

        $payloads = [];

        foreach ($collection->getLinks() as $link) {
            if (!$link instanceof LinkInterface) {
                continue;
            }

            $payload = $link->getSerializedValues();

            if ($link instanceof ElementLink) {
                $payload = $this->localizeElementLinkPayload($payload, $sourceSiteId, $targetSiteId);
            }

            $payloads[] = $payload;
        }

        return new LinkCollection($field, $payloads, $element);
    }

    public function propagateLinkStructure(HyperField $field, ElementInterface $element, LinkCollectionInterface $collection): void
    {
        if (!$field->multipleLinks || $element->propagating) {
            return;
        }

        if ($field->translationMethod !== Field::TRANSLATION_METHOD_SITE) {
            return;
        }

        if (!$element->id || !$element->siteId || !$element::isLocalized()) {
            return;
        }

        if (!$element instanceof Entry) {
            return;
        }

        if (!$element->isFieldDirty($field->handle)) {
            return;
        }

        $key = $element->id . ':' . $element->siteId . ':' . $field->id;

        if (isset($this->_propagating[$key])) {
            return;
        }

        $this->_propagating[$key] = true;

        try {
            foreach ($element->getLocalized()->all() as $localizedElement) {
                if (!$localizedElement instanceof Entry || $localizedElement->siteId === $element->siteId) {
                    continue;
                }

                $existing = $localizedElement->getFieldValue($field->handle);

                if (!$existing instanceof LinkCollection) {
                    $existing = new LinkCollection($field, [], $localizedElement);
                }

                if ($existing->isEmpty()) {
                    $merged = $this->localizeLinkCollection(
                        $field,
                        $collection,
                        $localizedElement,
                        $element->siteId,
                    );
                } else {
                    $merged = $this->mergeStructuralLinks(
                        $field,
                        $collection,
                        $existing,
                        $element->siteId,
                        $localizedElement->siteId,
                        $localizedElement,
                    );
                }

                if ($this->_serializeLinks($merged) === $this->_serializeLinks($existing)) {
                    continue;
                }

                $localizedElement->setFieldValue($field->handle, $merged);
                Craft::$app->getElements()->saveElement($localizedElement, false, false, false);
            }
        } finally {
            unset($this->_propagating[$key]);
        }
    }

    public function mergeStructuralLinks(
        HyperField $field,
        LinkCollectionInterface $source,
        LinkCollectionInterface $target,
        int $sourceSiteId,
        int $targetSiteId,
        ?ElementInterface $targetElement = null,
    ): LinkCollection {
        $sourceLinks = $source->getLinks();
        $targetLinks = $target->getLinks();
        $payloads = [];

        foreach ($sourceLinks as $index => $sourceLink) {
            if (!$sourceLink instanceof LinkInterface) {
                continue;
            }

            $payload = $sourceLink->getSerializedValues();
            $targetLink = $targetLinks[$index] ?? null;

            if ($targetLink instanceof LinkInterface) {
                if ($targetLink->getCustomLinkText() !== null && $targetLink->getCustomLinkText() !== '') {
                    $payload['linkText'] = $targetLink->getCustomLinkText();
                }

                $targetPayload = $targetLink->getSerializedValues();

                if (!empty($targetPayload['fields'])) {
                    $payload['fields'] = $targetPayload['fields'];
                }
            }

            if ($sourceLink instanceof ElementLink) {
                $payload = $this->localizeElementLinkPayload($payload, $sourceSiteId, $targetSiteId);
            }

            $payloads[] = $payload;
        }

        return $field->normalizeValue($payloads, $targetElement);
    }

    public function localizeElementLinkPayload(array $payload, int $sourceSiteId, int $targetSiteId): array
    {
        $linkValue = $payload['linkValue'] ?? null;

        if (is_array($linkValue)) {
            $referenceId = (int)($linkValue[0] ?? 0) ?: null;
        } else {
            $referenceId = (int)$linkValue ?: null;
        }

        if (!$referenceId) {
            return $payload;
        }

        $element = $this->resolveElement(
            EntryLink::elementType(),
            $referenceId,
            $targetSiteId,
            Entry::STATUS_LIVE,
            static function(ElementQueryInterface $query, mixed $status): void {
                if ($status === Element::STATUS_ENABLED || $status === Entry::STATUS_LIVE) {
                    $query->status(Entry::STATUS_LIVE);
                }
            },
        );

        if ($element) {
            $payload['linkValue'] = [$element->id];
            $payload['linkSiteId'] = $targetSiteId;
        }

        return $payload;
    }


    // Private Methods
    // =========================================================================

    private function _serializeLinks(LinkCollectionInterface $collection): string
    {
        return json_encode($collection->serializeValues());
    }
}
