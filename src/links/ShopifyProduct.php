<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use craft\shopify\elements\Product as ShopifyProductElement;

class ShopifyProduct extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function elementType(): string
    {
        return ShopifyProductElement::class;
    }
}
