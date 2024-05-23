<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\elements\User as UserElement;

class User extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('app', 'User');
    }

    public static function elementType(): string
    {
        return UserElement::class;
    }

    public static function checkElementUri(): bool
    {
        return false;
    }
}
