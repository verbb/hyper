<?php
namespace verbb\hyper\fieldlayoutelements;

use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField;

class LinkField extends TextField
{
    // Properties
    // =========================================================================

    public string $attribute = 'linkValue';
    public ?HyperField $field = null;
    public ?LinkInterface $link = null;
    public bool $mandatory = true;
    public bool $requirable = true;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        unset(
            $config['mandatory'],
            $config['autofocus'],
            $config['linkType'],
            $config['requirable'],
        );

        parent::__construct($config);
    }
    
    public function showAttribute(): bool
    {
        return true;
    }

    public function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('hyper', 'Link');
    }


    // Protected Methods
    // =========================================================================

    protected function inputHtml(ElementInterface $element = null, bool $static = false): ?string
    {
        if ($this->link) {
            return $this->link->getInputHtml($this, $this->field);
        }

        return null;
    }
}
