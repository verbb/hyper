<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use craft\commerce\elements\Variant as VariantElement;

class Variant extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function elementType(): string
    {
        return VariantElement::class;
    }

    public static function checkElementUri(): bool
    {
        return false;
    }
}
