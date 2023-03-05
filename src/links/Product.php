<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use craft\commerce\elements\Product as ProductElement;

class Product extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function elementType(): string
    {
        return ProductElement::class;
    }
}
