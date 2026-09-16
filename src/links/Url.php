<?php
namespace verbb\hyper\links;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\helpers\UrlSafety;

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

    public function setAttributes($values, $safeOnly = true): void
    {
        if (($fixed = $this->_getFixedLinkValue()) !== null) {
            $values['linkValue'] = $fixed;
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function getLinkUrl(): ?string
    {
        return $this->_getFixedLinkValue() ?? parent::getLinkUrl();
    }

    public function getSerializedValues(): array
    {
        $values = parent::getSerializedValues();

        // Enforce the setting for imported/programmatic values as well as CP input.
        if (($fixed = $this->_getFixedLinkValue()) !== null) {
            $values['linkValue'] = $fixed;
        }

        return $values;
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

        $extraSchemes = Hyper::$plugin?->getSettings()->allowedUriSchemes ?? [];

        // Reject executable / non-allowlisted schemes before Craft's UrlValidator
        // (which only understands http(s)-shaped values).
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
            if (!UrlSafety::isAllowedUrl($value, $extraSchemes)) {
                $this->addError($attribute, Craft::t('hyper', 'Please enter a valid URL.'));
            }

            return;
        }

        $validator = new UrlValidator(['enableIDN' => App::supportsIdn()]);
        $validator->validateAttribute($this, $attribute);
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['linkValue'], 'validateLinkValue'];

        return $rules;
    }


    // Private Methods
    // =========================================================================

    private function _getFixedLinkValue(): ?string
    {
        return $this->fixedLinkValue && $this->defaultLinkValue !== null && $this->defaultLinkValue !== ''
            ? $this->defaultLinkValue
            : null;
    }
}
