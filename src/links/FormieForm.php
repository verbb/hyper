<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;

use verbb\formie\elements\Form as FormElement;

class FormieForm extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Form');
    }

    public static function elementType(): string
    {
        return FormElement::class;
    }

    public static function checkElementUri(): bool
    {
        return false;
    }
}
