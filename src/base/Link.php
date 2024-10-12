<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\helpers\Html;
use verbb\hyper\links\MissingLink;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use Twig\Markup;

abstract class Link extends Element implements LinkInterface
{
    // Constants
    // =========================================================================

    public const SCENARIO_SETTINGS = 'settings';


    // Static Methods
    // =========================================================================

    public static function hasContent(): bool
    {
        return true;
    }

    public static function classDisplayName(): string
    {
        $classNameParts = explode('\\', static::class);

        return array_pop($classNameParts);
    }

    public static function displayName(): string
    {
        return Craft::t('hyper', static::classDisplayName());
    }

    public static function classDisplayNameSlug(): string
    {
        return StringHelper::toKebabCase(static::classDisplayName());
    }

    public static function lowerClassDisplayName(): string
    {
        return StringHelper::toLowerCase(static::classDisplayName());
    }

    public static function linkValuePlaceholder(): ?string
    {
        return null;
    }

    public static function getRequiredPlugins(): array
    {
        return [];
    }

    public static function gqlTypeNameByContext(mixed $context): string
    {
        $linkTypeHandle = StringHelper::toPascalCase($context->label);

        return $context->field->handle . '_' . $linkTypeHandle . '_LinkType';
    }

    public static function checkElementUri(): bool
    {
        return false;
    }

    public static function getDefaultFieldLayout(): FieldLayout
    {
        $fieldLayout = new FieldLayout([
            'type' => static::class,
        ]);

        // Populate the field layout
        $tab1 = new FieldLayoutTab(['name' => 'Content']);
        $tab1->setLayout($fieldLayout);

        $tab1->setElements([
            Craft::createObject([
                'class' => LinkField::class,
                'width' => 50,
            ]),
            Craft::createObject([
                'class' => LinkTextField::class,
                'width' => 50,
                'placeholder' => Craft::t('hyper', 'e.g. Read more'),
            ]),
        ]);

        $tab2 = new FieldLayoutTab(['name' => 'Advanced']);
        $tab2->setLayout($fieldLayout);
        
        $tab2->setElements([
            Craft::createObject([
                'class' => LinkTitleField::class,
            ]),
            Craft::createObject([
                'class' => ClassesField::class,
            ]),
            Craft::createObject([
                'class' => CustomAttributesField::class,
            ]),
        ]);

        $fieldLayout->setTabs([$tab1, $tab2]);

        return $fieldLayout;
    }


    // Properties
    // =========================================================================

    public ?string $label = null;
    public ?string $handle = null;
    public bool $enabled = true;
    public bool $isCustom = false;
    public bool $isNew = false;
    public ?string $layoutUid = null;
    public ?array $layoutConfig = null;

    public ?bool $newWindow = null;
    public mixed $linkValue = null;
    public ?string $linkText = null;
    public ?string $ariaLabel = null;
    public ?string $urlSuffix = null;
    public ?string $linkTitle = null;
    public ?string $classes = null;
    public array $customAttributes = [];
    public array $fields = [];

    public ?HyperField $field = null;
    public bool $isFieldRequired = false;

    private ?FieldLayout $_fieldLayout = null;


    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        // Needed to override the element title
        $this->title = $this->linkTitle;
    }

    public function __toString(): string
    {
        return (string)$this->getUrl();
    }

    public function __debugInfo()
    {
        // For developer AX with `dd` and `dump`, keep things lean.
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            return $this->getSerializedValues();
        }

        return get_object_vars($this);
    }

    public function __call($name, $params): mixed
    {
        // Prevent a hard error being thrown when referencing a property that might not exist. This helps templating be leaner
        // for custom fields that might be existing for one link type, but not another. Rather than throwing a heap of conditionals
        // around the link type in Twig, just return null if not found.
        if (property_exists($this, $name)) {
            return $this->$name;
        }

        if (method_exists($this, $name)) {
            return $this->$name($params);
        }

        return null;
    }

    public function count(): int|bool
    {
        return mb_strlen((string)$this, Craft::$app->charset);
    }

    public function isEmpty(): bool
    {
        return !$this->count();
    }

    public function isElement(): bool
    {
        return $this instanceof ElementLink;
    }

    public function scenarios(): array
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_SETTINGS] = [];

        return $scenarios;
    }

    public function getSettingsConfig(): array
    {
        // Return the settings used in the Vue component
        return [
            'type' => get_class($this),
            'label' => $this->label,
            'handle' => $this->handle,
            'enabled' => $this->enabled,
            'isCustom' => $this->isCustom,
            'layoutUid' => $this->layoutUid,
            'layoutConfig' => $this->layoutConfig,
        ];
    }

    public function getSettingsConfigForDb(): array
    {
        // Return the config that will be saved to the field settings
        return $this->getSettingsConfig();
    }

    public function getInputConfig(): array
    {
        return [
            'type' => get_class($this),
            'handle' => $this->handle,
            'newWindow' => $this->newWindow,
            'linkValue' => $this->linkValue,
            'linkText' => $this->linkText,
            'ariaLabel' => $this->ariaLabel,
            'urlSuffix' => $this->urlSuffix,
            'linkTitle' => $this->linkTitle,
            'classes' => $this->classes,
            'isNew' => $this->isNew,
            'customAttributes' => $this->customAttributes,
            'fields' => $this->fields,
        ];
    }

    public function getSerializedValues(): array
    {
        // Convert custom fields from using their handles to the fieldLayoutUid's
        $fieldContent = [];

        foreach ($this->fields as $fieldHandle => $value) {
            if ($field = $this->getFieldLayout()->getFieldByHandle($fieldHandle)) {
                $fieldContent[$field->layoutElement->uid] = $value;
            }
        }

        // Return the values used in the Vue component, and what will be saved to the content table
        return array_filter([
            'type' => get_class($this),
            'handle' => $this->handle,
            'newWindow' => $this->newWindow,
            'linkValue' => $this->linkValue,
            'linkText' => $this->linkText,
            'ariaLabel' => $this->ariaLabel,
            'urlSuffix' => $this->urlSuffix,
            'linkTitle' => $this->linkTitle,
            'classes' => $this->classes,
            'customAttributes' => $this->customAttributes,
            'fields' => $fieldContent,
        ], function($value) {
            // Filter out any empty values (`false` and `0` are okay)
            return ($value !== null && $value !== '' && $value !== []);
        });
    }

    public function getSettingsHtmlVariables(): array
    {
        return [
            'linkType' => $this,
        ];
    }

    public function getSettingsHtml(): ?string
    {
        $handle = static::classDisplayNameSlug();

        $variables = $this->getSettingsHtmlVariables();

        return Craft::$app->getView()->renderTemplate("hyper/links/$handle/settings", $variables);
    }

    public function getCustomFields(): array
    {
        if ($fieldLayout = $this->getFieldLayout()) {
            return $fieldLayout->getCustomFields();
        }

        return [];
    }

    public function getFieldLayout(): ?FieldLayout
    {
        if ($this->_fieldLayout !== null) {
            return $this->_fieldLayout;
        }

        if ($this->layoutUid) {
            $this->_fieldLayout = Craft::$app->getFields()->getLayoutByUid($this->layoutUid);
        }

        return $this->_fieldLayout;
    }

    public function setFieldLayout(FieldLayout $fieldLayout): void
    {
        $this->_fieldLayout = $fieldLayout;
    }

    public function getInputHtmlVariables(LinkField $layoutField, HyperField $field): array
    {
        return [
            'layoutField' => $layoutField,
            'field' => $field,
            'link' => $this,
        ];
    }

    public function getInputHtml(LinkField $layoutField, HyperField $field): ?string
    {
        $handle = static::classDisplayNameSlug();

        $variables = $this->getInputHtmlVariables($layoutField, $field);

        return Craft::$app->getView()->renderTemplate("hyper/links/$handle/input", $variables);
    }

    public function getTabCount(): ?int
    {
        if ($fieldLayout = $this->getFieldLayout()) {
            return count($fieldLayout->getTabs());
        }

        return null;
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        // Needed to override the element title
        if (isset($values['linkTitle'])) {
            $this->title = $values['linkTitle'];
        }

        // Prevent setting values retained when removed from field layout. Otherwise, stale values
        if ($fieldLayout = $this->getFieldLayout()) {
            $customFields = $values['fields'] ?? [];
            $nativeFields = ArrayHelper::getColumn($fieldLayout->getAvailableNativeFields(), 'attribute');

            // Remove any native field (attribute) that aren't included in the field layout
            foreach ($nativeFields as $nativeField) {
                if (!$fieldLayout->isFieldIncluded($nativeField)) {
                    if (array_key_exists($nativeField, $values)) {
                        unset($values[$nativeField]);
                    }
                }
            }

            $fieldContent = [];

            // Convert from layoutElementUid saved to the database to handle
            foreach ($customFields as $handle => $fieldValue) {
                // Check if this is a handle or layoutElementUid - we migrated to the latter in Hyper 2.x
                // So this check can eventually be removed at the next breakpoint, as we only store the UID
                // But this would be a mammoth migration task trying to find all content for a Hyper field instance
                // (note, not just a Hyper field, because you can create field instances)
                if (str_contains($handle, '-')) {
                    foreach ($fieldLayout->getCustomFields() as $field) {
                        if ($field->layoutElement && $field->layoutElement->uid === $handle) {
                            $fieldContent[$field->handle] = $fieldValue;
                        }
                    }
                } else {
                    $fieldContent[$handle] = $fieldValue;
                }
            }

            // Remove any custom fields that aren't included in the field layout
            foreach ($fieldContent as $handle => $fieldValue) {
                if (!$fieldLayout->isFieldIncluded($handle)) {
                    unset($fieldContent[$handle]);
                }
            }

            $values['fields'] = $fieldContent;
            $this->setFieldValues($fieldContent);
        }

        // Check if new window is disabled at the field level
        if ($this->field && !$this->field->newWindow) {
            $values['newWindow'] = false;
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function getElement(mixed $status = null): ?ElementInterface
    {
        return null;
    }

    public function hasElement(mixed $status = null): bool
    {
        return false;
    }

    public function getType(): string
    {
        return get_class($this);
    }

    public function getLinkType(): ?LinkInterface
    {
        return ArrayHelper::firstWhere($this->field->getLinkTypes(), 'handle', $this->handle);
    }

    public function getNewWindow(): ?bool
    {
        return $this->newWindow;
    }

    public function getLinkText(): ?string
    {
        return $this->linkText;
    }

    public function getLinkUrl(): ?string
    {
        return App::parseEnv((string)$this->linkValue);
    }

    public function getUrl(): ?string
    {
        return trim($this->getUrlPrefix() . $this->getLinkUrl() . $this->getUrlSuffix()) ?: null;
    }

    public function getLinkUri(): ?string
    {
        return $this->getElement()?->uri ?? null;
    }

    public function getText(?string $defaultText = null): ?string
    {
        // If there's not a valid URL for this link, don't return text even if there is a value
        if (!$this->getUrl()) {
            return null;
        }

        $defaultText = $defaultText ?? Craft::t('hyper', 'Read more');

        // Use the placeholder of the `linkText` field as fallback
        if ($fieldLayout = $this->getFieldLayout()) {
            if ($fieldLayout->isFieldIncluded('linkText')) {
                $defaultText = $fieldLayout->getField('linkText')->placeholder ?? $defaultText;
            }

            // Swap the plugin default `e.g. Read more` to just `Read nore`;
            $defaultText = $defaultText === Craft::t('hyper', 'e.g. Read more') ? Craft::t('hyper', 'Read more') : $defaultText;
        }

        return $this->getLinkText() ?: $defaultText ?: null;
    }

    public function getTarget(): ?string
    {
        return ($this->getNewWindow()) ? '_blank' : null;
    }

    public function getAriaLabel(): ?string
    {
        return $this->ariaLabel;
    }

    public function getTitle(): ?string
    {
        return $this->getLinkTitle();
    }

    public function getLinkTitle(): ?string
    {
        return $this->linkTitle;
    }

    public function getUrlPrefix(): ?string
    {
        return null;
    }

    public function getUrlSuffix(): ?string
    {
        return $this->urlSuffix;
    }

    public function getClasses(): ?string
    {
        return $this->classes;
    }

    public function getCustomAttributes(): array
    {
        $attributes = [];

        foreach ($this->customAttributes as $value) {
            $attributes[$value['attribute']] = $value['value'];
        }

        return $attributes;
    }

    public function getLink(array $attributes = []): ?Markup
    {
        if (!$this->getUrl()) {
            return null;
        }

        // Rip out any custom text and use that. Note that this overrides `getText()`
        $text = ArrayHelper::remove($attributes, 'text') ?? $this->getText();

        $attributes = $this->getLinkAttributes($attributes);

        return Template::raw(Html::tag('a', $text, $attributes));
    }

    public function getLinkAttributes(array $attributes = [], bool $asString = false): array|Markup
    {
        $attr = [];

        if ($classes = $this->getClasses()) {
            $attr['class'] = $classes;
        }

        if ($href = $this->getUrl()) {
            $attr['href'] = $href;
        }

        if ($title = $this->getLinkTitle()) {
            $attr['title'] = $title;
        }

        if ($ariaLabel = $this->getAriaLabel()) {
            $attr['aria-label'] = $ariaLabel;
        }

        if ($this->getNewWindow()) {
            $attr['target'] = '_blank';
            $attr['rel'] = 'noopener noreferrer';
        }

        // Merge attributes in a specific order to allow template-provided attributes to override everything.
        // The order should be built-in (above), Custom Attribute field settings, and template-provided attributes.
        $attributes = $this->_mergeAttributes($attr, $this->getCustomAttributes(), $attributes);
        $attributes = array_filter($attributes);

        if ($asString) {
            return Template::raw(Html::renderTagAttributes($attributes));
        }

        return $attributes;
    }

    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext($this);
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['label', 'handle', 'enabled', 'newWindow', 'linkValue', 'linkText', 'ariaLabel', 'urlSuffix', 'linkTitle', 'classes', 'customAttributes'], 'safe'];

        // Validation for only when saving Hyper fields and their settings
        $rules[] = [['label', 'handle'], 'required', 'on' => [self::SCENARIO_SETTINGS]];

        if ($this->isFieldRequired) {
            $rules[] = [['linkValue'], 'required', 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]];
        }

        if ($fieldLayout = $this->getFieldLayout()) {
            foreach ($fieldLayout->getTabs() as $tab) {
                foreach ($tab->getElements() as $layoutElement) {
                    if ($layoutElement instanceof BaseNativeField && $layoutElement->required) {
                        $rules[] = [[$layoutElement->attribute], 'required', 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]];
                    }
                }
            }
        }

        return $rules;
    }


    // Private Methods
    // =========================================================================

    private function _mergeAttributes(array $attributes1, array $attributes2, array $attributes3 = []): array
    {
        $attributes1 = Html::normalizeTagAttributes($attributes1);
        $attributes2 = Html::normalizeTagAttributes($attributes2);
        $attributes3 = Html::normalizeTagAttributes($attributes3);
        $attributes = ArrayHelper::merge($attributes1, $attributes2, $attributes3);

        // Ensure we don't have any duplicate classes
        if (isset($attributes['class']) && is_array($attributes['class'])) {
            $attributes['class'] = array_unique($attributes['class']);
        }

        // Handle `rel` attributes which can be an array
        if (isset($attributes['rel']) && is_array($attributes['rel'])) {
            $attributes['rel'] = implode(' ', $attributes['rel']);
        }

        return $attributes;
    }

}
