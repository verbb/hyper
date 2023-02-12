<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use craft\elements\Category as CategoryElement;

class Category extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function elementType(): string
    {
        return CategoryElement::class;
    }
}
