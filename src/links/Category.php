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
        return Craft::t('app', 'Category');
    }

    public static function elementType(): string
    {
        return CategoryElement::class;
    }

    public static function supportsSourceUriFiltering(): bool
    {
        return true;
    }

    public static function limitSourcesLabel(): string
    {
        return Craft::t('hyper', 'Limit Sources to Category Groups with URIs');
    }
}
