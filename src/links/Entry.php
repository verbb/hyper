<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use craft\elements\Entry as EntryElement;

class Entry extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Entry');
    }

    public static function elementType(): string
    {
        return EntryElement::class;
    }

}
