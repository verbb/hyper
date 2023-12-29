<?php
namespace verbb\hyper\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField;

class AriaLabelField extends TextField
{
    // Properties
    // =========================================================================

    public string $attribute = 'ariaLabel';
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
        return Craft::t('hyper', 'ARIA Label');
    }

    public function defaultInstructions(ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('hyper', 'The aria-label for the link.');
    }
}
