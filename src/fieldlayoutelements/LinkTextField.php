<?php
namespace verbb\hyper\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;

class LinkTextField extends TextField
{
    // Properties
    // =========================================================================

    public string $attribute = 'linkText';
    public bool $requirable = true;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        unset(
            $config['mandatory'],
            $config['autofocus']
        );

        parent::__construct($config);
    }
    
    public function showAttribute(): bool
    {
        return true;
    }

    public function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('hyper', 'Link Text');
    }

    public function defaultPlaceholder(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('hyper', 'e.g. Read more');
    }
}
