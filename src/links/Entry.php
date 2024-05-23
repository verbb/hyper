<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\elements\Entry as EntryElement;

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

}
