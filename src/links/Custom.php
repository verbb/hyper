<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

use Craft;

class Custom extends Link
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Custom');
    }

    
    // Properties
    // =========================================================================

    public ?string $placeholder = null;


    // Public Methods
    // =========================================================================

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['placeholder'] = $this->placeholder;

        return $values;
    }

    public function defaultPlaceholder(): ?string
    {
        return '';
    }

}
