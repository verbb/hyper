<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use craft\elements\User as UserElement;

class User extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function elementType(): string
    {
        return UserElement::class;
    }
}
