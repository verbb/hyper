<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\fieldlayoutelements\LinkField;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;

abstract class ElementLink extends Link
{
    // Static Methods
    // =========================================================================

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('app', 'Choose');
    }

    public static function checkElementUri(): bool
    {
        return true;
    }


    // Abstract Methods
    // =========================================================================

    abstract public static function elementType(): string;


    // Properties
    // =========================================================================

    public ?int $linkSiteId = null;
    public string|array|null $sources = '*';
    public ?string $selectionLabel = null;

    private ?ElementInterface $_element = null;
    private ?ElementInterface $_elementCache = null;


    // Public Methods
    // =========================================================================

    public function setAttributes($values, $safeOnly = true): void
    {
        // Protect against invalid values for some link types. This can happen due to migrations gone wrong
        // https://github.com/verbb/hyper/issues/10
        $linkValue = $values['linkValue'] ?? null;

        if (is_string($linkValue)) {
            // Cast to an integer to ensure it's a valid ID (it might still be a string)
            $values['linkValue'] = (int)$linkValue ?: null;
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['sources'] = $this->sources;
        $values['selectionLabel'] = $this->selectionLabel;

        return $values;
    }

    public function getSerializedValues(): array
    {
        $values = parent::getSerializedValues();
        $values['linkSiteId'] = $this->linkSiteId;

        // Don't save element link values as IDs, which come from the element select
        if (isset($values['linkValue']) && is_array($values['linkValue'])) {
            $values['linkValue'] = $values['linkValue'][0] ?? null;
        }

        return $values;
    }

    public function getSettingsHtmlVariables(): array
    {
        $variables = parent::getSettingsHtmlVariables();

        /** @var ElementInterface|string $elementType */
        $elementType = static::elementType();
        $variables['lowerElementType'] = $elementType::lowerDisplayName();
        $variables['pluralElementType'] = $elementType::pluralLowerDisplayName();

        return $variables;
    }

    public function getInputHtmlVariables(LinkField $layoutField, HyperField $field): array
    {
        $variables = parent::getInputHtmlVariables($layoutField, $field);

        /** @var ElementInterface|string $elementType */
        $elementType = static::elementType();
        $variables['lowerElementType'] = $elementType::lowerDisplayName();
        $variables['pluralElementType'] = $elementType::pluralLowerDisplayName();

        return $variables;
    }

    public function getSourceOptions(): array
    {
        $options = [];

        $sources = Craft::$app->getElementSources()->getSources(static::elementType(), 'modal');

        foreach ($sources as $source) {
            if (!isset($source['heading'])) {
                $options[] = [
                    'label' => $source['label'],
                    'value' => $source['key']
                ];
            }
        }

        // Sort alphabetically by label
        usort($options, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        return $options;
    }

    public function getAvailableSources(): string|array|null
    {
        return $this->sources;
    }

    public function getElements(): array
    {
        $elements = [];

        // Temp normalization during development
        if (is_array($this->linkValue)) {
            $this->linkValue = $this->linkValue[0] ?? null;
        }

        if ($this->linkValue) {
            $element = Craft::$app->getElements()->getElementById($this->linkValue, static::elementType(), $this->linkSiteId);

            if ($element) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    public function getElement(mixed $status = Element::STATUS_ENABLED): ?ElementInterface
    {
        if ($this->_element) {
            return $this->_element;
        }

        if (!$this->linkValue) {
            return null;
        }

        /** @var ElementInterface|string $elementType */
        $elementType = static::elementType();

        $query = $elementType::find()
            ->id($this->linkValue)
            ->siteId($this->linkSiteId)
            ->status($status);

        return $this->_element = $query->one();
    }

    public function hasElement(mixed $status = Element::STATUS_ENABLED): bool
    {
        return (bool)$this->getElement($status);
    }

    public function getLinkUrl(): ?string
    {
        if ($cached = $this->_getElementCache()) {
            // Asset links skip the cache for the moment, as they're more complicated than a `uri`
            if (!($cached instanceof Asset)) {
                return $cached->url;
            }
        }

        if ($element = $this->getElement()) {
            return $element->url;
        }

        return null;
    }

    public function getLinkText(): ?string
    {
        if ($this->linkText) {
            return $this->linkText;
        }

        if ($cached = $this->_getElementCache()) {
            return $cached->title;
        }

        if ($element = $this->getElement()) {
            return (string)$element;
        }

        return null;
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['sources'], 'required', 'when' => function($model) {
            return $model->enabled;
        }];

        return $rules;
    }


    // Private Methods
    // =========================================================================

    private function _getElementCache(): ?ElementInterface
    {
        if ($this->_elementCache) {
            return $this->_elementCache;
        }

        // Temp normalization during development
        if (is_array($this->linkValue)) {
            $this->linkValue = $this->linkValue[0] ?? null;
        }

        if ($cached = Hyper::$plugin->getElementCache()->getCache($this->linkValue, $this->linkSiteId)) {
            $elementType = static::elementType();

            $element = new $elementType($cached);

            // Ensure we only return for "live" elements
            if ($element && $element->status === Element::STATUS_ENABLED) {
                return $this->_elementCache = $element;
            }
        }

        return null;
    }

}
