<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;

use craft\shopify\elements\Product as ShopifyProductElement;

class ShopifyProduct extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Shopify Product');
    }

    public static function getRequiredPlugins(): array
    {
        return ['shopify'];
    }

    public static function elementType(): string
    {
        return ShopifyProductElement::class;
    }
}
