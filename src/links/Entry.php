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
        // Modify the status for entries, which have `STATUS_LIVE` vs `STATUS_ENABLED`.
        // Equate querying for enabled statuses to be the same as live (by default), unless passing otherwise.
        if ($status === Element::STATUS_ENABLED) {
            $query->status(EntryElement::STATUS_LIVE);
        }
    }


    // Protected Methods
    // =========================================================================

    protected function createSelectionCondition(): ?ElementConditionInterface
    {
        $condition = EntryElement::createCondition();
        // Sources already cover section filters; hide duplicate section rules in the builder.
        $condition->queryParams = ['section', 'sectionId'];

        return $condition;
    }
}
