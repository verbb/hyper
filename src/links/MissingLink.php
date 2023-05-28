<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

use Craft;
use craft\base\MissingComponentInterface;
use craft\base\MissingComponentTrait;

class MissingLink extends Link implements MissingComponentInterface
{
    // Traits
    // =========================================================================

    use MissingComponentTrait;


    // Properties
    // =========================================================================

    public ?int $linkSiteId = null;
    public string|array|null $sources = '*';
    public ?string $selectionLabel = null;


    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Missing Link');
    }
}
