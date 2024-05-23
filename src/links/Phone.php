<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

use Craft;

class Phone extends Link
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Phone');
    }

    
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

    public function validatePhone(string $attribute): void
    {
        $isValid = filter_var($this->$attribute, FILTER_VALIDATE_REGEXP, [
            'options' => [
                'regexp' => '/^[0-9+\(\)#\.\s\/ext-]+$/',
            ],
        ]);

        if (!$isValid) {
            $this->addError($attribute, Craft::t('hyper', 'Please enter a valid phone number.'));
        }
    }

    public function defaultPlaceholder(): ?string
    {
        return '';
    }

    public function getUrlPrefix(): ?string
    {
        return $this->getLinkUrl() ? 'tel:' : null;
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        
        $rules[] = [['linkValue'], 'validatePhone'];

        return $rules;
    }

}
