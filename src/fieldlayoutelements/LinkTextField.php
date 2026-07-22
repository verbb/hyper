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
    public ?string $defaultValue = null;


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


    // Protected Methods
    // =========================================================================

    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        // Seed empty CP inputs with the layout default so new links show “Learn More” etc
        if ($element && $this->defaultValue !== null && $this->defaultValue !== '') {
            $current = $element->getFieldValue($this->attribute());

            if ($current === null || $current === '') {
                $element->{$this->attribute()} = $this->defaultValue;
            }
        }

        return parent::inputHtml($element, $static);
    }
}
