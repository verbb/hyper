<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

use Craft;
use craft\helpers\App;
use craft\validators\UrlValidator;

class Url extends Link 
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'URL');
    }

    public static function linkValuePlaceholder(): ?string
    {
        return rtrim(Craft::$app->getSites()->primarySite->baseUrl, '/');
    }

    public static function supportsBulkCreation(): bool
    {
        return true;
    }

    public static function bulkCreationMode(): ?string
    {
        return 'text';
    }


    // Properties
    // =========================================================================

    public ?string $placeholder = null;
    public ?string $defaultLinkValue = null;
    public bool $fixedLinkValue = false;


    // Public Methods
    // =========================================================================

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['placeholder'] = $this->placeholder;
        $values['defaultLinkValue'] = $this->defaultLinkValue;
        $values['fixedLinkValue'] = $this->fixedLinkValue;

        return $values;
    }

    public function defaultPlaceholder(): ?string
    {
        return rtrim(Craft::$app->getSites()->primarySite->baseUrl, '/');
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['linkValue'], 'validateLinkValue'];

        return $rules;
    }

    public function validateLinkValue(string $attribute): void
    {
        $value = trim((string)($this->$attribute ?? ''));

        if ($value === '') {
            return;
        }

        // Hash-only anchors
        if (str_starts_with($value, '#')) {
            return;
        }

        // Custom URI schemes such as slack:// or ftp://
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
            return;
        }

        $validator = new UrlValidator(['enableIDN' => App::supportsIdn()]);
        $validator->validateAttribute($this, $attribute);
    }

}
