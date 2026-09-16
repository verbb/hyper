<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\base\Element;
use craft\elements\Entry as EntryElement;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;

class Entry extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('app', 'Entry');
    }

    public static function elementType(): string
    {
        return EntryElement::class;
    }

    public static function supportsSourceUriFiltering(): bool
    {
        return true;
    }

    public static function limitSourcesLabel(): string
    {
        return Craft::t('hyper', 'Limit Sources to Sections with URIs');
    }

    public function modifyElementQuery(ElementQueryInterface $query, mixed $status = Element::STATUS_ENABLED): void
    {
        $query->status($this->normalizeElementStatus($status));
    }


    // Protected Methods
    // =========================================================================

    protected function normalizeElementStatus(mixed $status): mixed
    {
        // Both database queries and cached entries must respect publication dates.
        return $status === Element::STATUS_ENABLED ? EntryElement::STATUS_LIVE : $status;
    }

    protected function createSelectionCondition(): ?ElementConditionInterface
    {
        $condition = EntryElement::createCondition();
        // Sources already cover section filters; hide duplicate section rules in the builder.
        $condition->queryParams = ['section', 'sectionId'];

        return $condition;
    }
}
