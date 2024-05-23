<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

use Craft;
use craft\helpers\App;

use yii\validators\EmailValidator;

class Email extends Link 
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Email');
    }

    public static function linkValuePlaceholder(): ?string
    {
        $domain = parse_url(Craft::$app->getSites()->primarySite->baseUrl)['host'] ?? '';

        if ($domain) {
            return "info@$domain";
        }

        return null;
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

    public function getUrlPrefix(): ?string
    {
        return $this->getLinkUrl() ? 'mailto:' : null;
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        
        $rules[] = [['linkValue'], EmailValidator::class, 'enableIDN' => App::supportsIdn()];

        return $rules;
    }

}
