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

        $config['placeholder'] = Craft::t('hyper', ($config['placeholder'] ?? $this->defaultPlaceholder()));

        parent::__construct($config);
    }
    
    public function showAttribute(): bool
    {
        return true;
    }

    public function defaultPlaceholder(?ElementInterface $element = null, bool $static = false): ?string
    {
        return '';
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('hyper/_includes/text-field-settings', [
            'field' => $this,
            'defaultLabel' => $this->defaultLabel(),
            'defaultInstructions' => $this->defaultInstructions(),
            'defaultPlaceholder' => $this->defaultPlaceholder(),
            'labelHidden' => !$this->showLabel(),
        ]);
    }
}
