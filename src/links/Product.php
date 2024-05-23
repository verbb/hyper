<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;

use craft\commerce\elements\Product as ProductElement;

class Product extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Product');
    }

    public static function elementType(): string
    {
        return ProductElement::class;
    }
}
