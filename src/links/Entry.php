<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\base\Element;
use craft\elements\Entry as EntryElement;
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

    public function modifyElementQuery(ElementQueryInterface $query, mixed $status = Element::STATUS_ENABLED): void
    {
        // Modify the status for entries, which have `STATUS_LIVE` vs `STATUS_ENABLED`.
        // Equate querying for enabled statuses to be the same as live (by default), unless passing otherwise.
        if ($status === Element::STATUS_ENABLED) {
            $query->status(EntryElement::STATUS_LIVE);
        }
    }

}
