<?php
namespace verbb\hyper\fieldlayoutelements;

use verbb\hyper\base\LinkInterface;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;

class NewWindowField extends BaseNativeField
{
    // Properties
    // =========================================================================

    public string $attribute = 'newWindow';
    public bool $requirable = false;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        unset(
            $config['mandatory'],
            $config['autofocus'],
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
        return Craft::t('hyper', 'New Window');
    }

    public function defaultInstructions(ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('hyper', 'Whether the link should open in a new window.');
    }

    public function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $on = false;
        $disabled = $static;

        if ($element instanceof LinkInterface) {
            $on = $element->getResolvedNewWindow();

            // Field-level “Enable New Window” must be on for authors to toggle.
            if ($element->field && !$element->field->newWindow) {
                $disabled = true;
                $on = false;
            }
        }

        return Cp::lightswitchHtml([
            'id' => $this->attribute(),
            'name' => $this->attribute(),
            'on' => $on,
            'disabled' => $disabled,
            'value' => 1,
        ]);
    }
}
