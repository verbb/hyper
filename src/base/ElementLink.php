<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\fieldlayoutelements\LinkField;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Asset;

use craft\commerce\elements\Variant;

abstract class ElementLink extends Link implements ElementLinkInterface
{
    // Static Methods
    // =========================================================================

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('app', 'Choose');
    }


    // Abstract Methods
    // =========================================================================

    abstract public static function elementType(): string;


    // Properties
    // =========================================================================

    public ?int $linkSiteId = null;
    public string|array|null $sources = '*';
    public ?string $selectionLabel = null;
    public bool $showSiteMenu = true;
    public bool $allowElementsWithoutUri = false;

    private ?ElementInterface $_element = null;
    private ?ElementInterface $_elementCache = null;


    // Public Methods
    // =========================================================================

    public function count(): int|bool
    {
        // Override `Link::count` to not rely on a URL, as not all elements have a URL, but still have a value
        return $this->getElement() ? true : false;
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        // Protect against invalid values for some link types. This can happen due to migrations gone wrong
        // https://github.com/verbb/hyper/issues/10
        $linkValue = $values['linkValue'] ?? [];

        // Normalize to an array. The value is only ever a single ID, but this help with change-detection in Vue
        // as the element select field produces an array as its value.
        if (!is_array($linkValue)) {
            $linkValue = [$linkValue];
        }

        foreach ($linkValue as $key => $value) {
            if (is_string($value)) {
                // Cast to an integer to ensure it's a valid ID (it might still be a string)
                $linkValue[$key] = (int)$value ?: null;
            }
        }

        $values['linkValue'] = $linkValue;

        parent::setAttributes($values, $safeOnly);
    }

    public function getInputConfig(): array
    {
        $values = parent::getInputConfig();
        $values['linkSiteId'] = $this->linkSiteId;

        return $values;
    }

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['sources'] = $this->sources;
        $values['selectionLabel'] = $this->selectionLabel;
        $values['showSiteMenu'] = $this->showSiteMenu;
        $values['allowElementsWithoutUri'] = $this->allowElementsWithoutUri;

        return $values;
    }

    public function getSerializedValues(): array
    {
        $values = parent::getSerializedValues();
        $values['linkSiteId'] = $this->linkSiteId;

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

        $this->modifyElementQuery($query, $status);

        return $this->_element = $query->one();
    }

    public function hasElement(mixed $status = Element::STATUS_ENABLED): bool
    {
        return (bool)$this->getElement($status);
    }

    public function modifyElementQuery(ElementQueryInterface $query, mixed $status = Element::STATUS_ENABLED): void
    {

    }

    public function getLinkUrl(): ?string
    {
        if ($cached = $this->_getElementCache()) {
            // Asset links skip the cache for the moment, as they're more complicated than a `uri`
            $skippedElementTypes = [Asset::class];

            // If a variant, there's some issues with it being cached and the parent product, so skip
            if (Hyper::$plugin->getService()->isPluginInstalledAndEnabled('commerce')) {
                $skippedElementTypes[] = Variant::class;
            }

            if (!in_array(get_class($cached), $skippedElementTypes)) {
                return $cached->getUrl();
            }
        }

        if ($element = $this->getElement()) {
            return $element->getUrl();
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

        $rules[] = [['allowElementsWithoutUri'], 'boolean'];

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
            if ($element && $element->getStatus() === Element::STATUS_ENABLED) {
                return $this->_elementCache = $element;
            }
        }

        return null;
    }

}
