<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

class Custom extends Link
{
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
