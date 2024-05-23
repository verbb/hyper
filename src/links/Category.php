<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\elements\Category as CategoryElement;

class Category extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Category');
    }

    public static function elementType(): string
    {
        return CategoryElement::class;
    }
}
