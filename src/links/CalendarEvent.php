<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;

use Solspace\Calendar\Elements\Event as EventElement;

class CalendarEvent extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Event');
    }

    public static function getRequiredPlugins(): array
    {
        return ['calendar'];
    }

    public static function elementType(): string
    {
        return EventElement::class;
    }

    public static function checkElementUri(): bool
    {
        return false;
    }
}
