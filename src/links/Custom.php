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

    public function defaultPlaceholder(): ?string
    {
        return '';
    }

}
