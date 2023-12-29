<?php
namespace verbb\hyper\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;

class CustomAttributesField extends BaseNativeField
{
    // Properties
    // =========================================================================

    public string $attribute = 'customAttributes';
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
        return Craft::t('hyper', 'Custom Attributes');
    }

    public function defaultInstructions(ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('hyper', 'Additional HTML attributes for the link.');
    }

    public function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Cp::editableTableFieldHtml([
            'id' => $this->attribute,
            'name' => $this->attribute,
            'cols' => [
                'attribute' => [
                    'type' => 'singleline',
                    'heading' => Craft::t('hyper', 'Attribute'),
                ],
                'value' => [
                    'type' => 'singleline',
                    'heading' => Craft::t('hyper', 'Value'),
                    'code' => true,
                ],
            ],
            'rows' => $element->customAttributes ?? [],
            'allowAdd' => true,
            'allowDelete' => true,
            'allowReorder' => true,
        ]);
    }
}
