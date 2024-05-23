<?php
namespace verbb\hyper\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField as CraftTextField;

class TextField extends CraftTextField
{
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

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('hyper/_includes/text-field-settings', [
            'field' => $this,
            'defaultLabel' => $this->defaultLabel(),
            'defaultInstructions' => $this->defaultInstructions(),
            'labelHidden' => !$this->showLabel(),
        ]);
    }
}
