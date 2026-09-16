<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\elements\conditions\LinkCondition;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\fieldlayoutelements\TextField;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\Html;
use verbb\hyper\helpers\UrlSafety;
use verbb\hyper\links\MissingLink;
use verbb\hyper\models\LinkInstance;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use Twig\Markup;

abstract class Link extends Element implements LinkInterface
{
    // Static Methods
    // =========================================================================

    public static function hasContent(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        // Custom relation fields use the link's owner site, just as on a saved element.
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

    public static function typeKey(): string
    {
        return lcfirst(static::classDisplayName());
    }

    public static function linkValuePlaceholder(): ?string
    {
        return null;
    }

    public static function createCondition(): ElementConditionInterface
    {
        return Craft::createObject(LinkCondition::class, [static::class]);
    }

    public static function supportsBulkCreation(): bool
    {
        return false;
    }

    public static function bulkCreationMode(): ?string
    {
        return null;
    }

    public static function getRequiredPlugins(): array
    {
        return [];
    }

    public static function gqlTypeNameByContext(mixed $context): string
    {
        // The instance handle is short and author-facing (e.g. `url`), so PascalCasing it
        // yields clean GraphQL type names like `linkField_Url_LinkType` rather than the old
        // `linkField_DefaultVerbbHyperLinksUrl_LinkType`.
        $handle = $context->handle ?: $context::typeKey();
        $field = $context->field;
        $sourceField = $field->uid ? Craft::$app->getFields()->getFieldByUid($field->uid) : null;

        // Layout aliases are local to a layout; different fields can share one.
        // Anchor schema identity to the original field while query keys keep aliases.
        return ($sourceField?->handle ?? $field->handle) . '_' . StringHelper::toPascalCase($handle) . '_LinkType';
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

    public static function isInstanceEmpty(LinkInstance $instance): bool
    {
        return !$instance->hasLinkValue() && !$instance->hasMeaningfulAttributes();
    }

    public static function resolveUrlFromInstance(LinkInstance $instance): ?string
    {
        if (!$instance->hasLinkValue()) {
            return null;
        }

        $linkValue = $instance->linkValue;

        if (is_array($linkValue)) {
            $linkValue = $linkValue[0] ?? null;
        }

        if (!is_scalar($linkValue) && $linkValue !== null) {
            return null;
        }

        return (string)$linkValue !== '' ? (string)$linkValue : null;
    }


    // Constants
    // =========================================================================

    public const SCENARIO_SETTINGS = 'settings';


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
    public ?string $uid = null;
    public ?HyperField $field = null;
    public ?int $ownerSiteId = null;
    public bool $isFieldRequired = false;

    private bool $_generatedUid = false;
    private ?FieldLayout $_fieldLayout = null;
    private bool $_hydratingFromInstance = false;


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
        return $this->isEmpty() ? 0 : 1;
    }

    public function isEmpty(): bool
    {
        return static::isInstanceEmpty($this->toInstance());
    }

    public function toInstance(): LinkInstance
    {
        $values = $this->getSerializedValues();
        $values['handle'] = $this->handle;
        $values['linkTypeHandle'] = $this->handle;

        if ($this->field) {
            return LinkInstance::fromSerialized($values, $this->field);
        }

        $instance = new LinkInstance();
        $instance->linkTypeHandle = (string)$this->handle;
        $instance->uid = $this->uid;
        $instance->newWindow = $this->newWindow;
        $instance->linkValue = $this->linkValue;
        $instance->linkText = $this->linkText;
        $instance->ariaLabel = $this->ariaLabel;
        $instance->urlSuffix = $this->urlSuffix;
        $instance->linkTitle = $this->linkTitle;
        $instance->classes = $this->classes;
        $instance->customAttributes = $this->customAttributes;
        $instance->fields = $values['fields'] ?? [];

        return $instance;
    }

    public function populateFromInstance(LinkInstance $instance): void
    {
        $this->_generatedUid = false;
        $this->_hydratingFromInstance = true;
        $this->setAttributes($instance->toLinkAttributes(), false);
        $this->_hydratingFromInstance = false;
    }

    public function clearContentState(): void
    {
        $this->newWindow = null;
        $this->linkValue = null;
        $this->linkText = null;
        $this->ariaLabel = null;
        $this->urlSuffix = null;
        $this->linkTitle = null;
        $this->classes = null;
        $this->customAttributes = [];
        $this->fields = [];
        $this->uid = null;
        $this->isNew = false;
        $this->ownerSiteId = null;
        $this->title = null;
    }

    public function isSettingsPrototype(): bool
    {
        return $this->getScenario() === self::SCENARIO_SETTINGS;
    }

    public function isElement(): bool
    {
        return $this instanceof ElementLink;
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
            'uid' => $this->uid,
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

    public function hasLegacyIdentity(): bool
    {
        return !$this->uid || $this->_generatedUid;
    }

    public function getSerializedValues(): array
    {
        // Mint a durable uid once so copy/cut/paste and structure sync can key links.
        if (!$this->uid) {
            $this->uid = StringHelper::UUID();
            $this->_generatedUid = true;
        }

        // Convert custom fields from using their handles to the fieldLayoutUid's
        $fieldContent = [];
        $fieldLayout = $this->getFieldLayout();

        foreach ($this->fields as $fieldHandle => $value) {
            $field = $fieldLayout?->getFieldByHandle($fieldHandle);

            // Programmatic field setters may receive normalized values such as
            // element queries or dates. Let the owning field serialize those.
            if ($field && is_object($value)) {
                $value = $field->serializeValue($this->getFieldValue($fieldHandle), $this);
            }

            // Missing custom fields retain their original key/value until restored.
            $fieldContent[$field?->layoutElement?->uid ?? $fieldHandle] = $value;
        }

        // Return the values used in the Vue component, and what will be saved to the content table
        return array_filter([
            'linkTypeHandle' => $this->handle,
            'uid' => $this->uid,
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

    public function hasLinkValue(): bool
    {
        return $this->toInstance()->hasLinkValue();
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

        if ($this->_fieldLayout === null && !empty($this->layoutConfig)) {
            $layoutConfig = $this->layoutConfig;

            if (is_string($layoutConfig)) {
                $layoutConfig = \craft\helpers\Json::decodeIfJson($layoutConfig);
            }

            if (is_array($layoutConfig)) {
                ArrayHelper::remove($layoutConfig, 'uid');
                $this->_fieldLayout = FieldLayout::createFromConfig($layoutConfig);
            }
        }

        return $this->_fieldLayout;
    }

    public function setFieldLayout(FieldLayout $fieldLayout): void
    {
        $this->_fieldLayout = $fieldLayout;

        // Keep serialized layoutConfig in sync so settings/DB round-trips preserve FLD tweaks.
        $this->layoutConfig = $fieldLayout->getConfig();
        $this->layoutUid = $fieldLayout->uid ?: $this->layoutUid;
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
        if ($fieldLayout = $this->getFieldLayout()) {
            if (!$fieldLayout->isFieldIncluded('linkValue')) {
                return '';
            }
        }

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

    public function getTabLabels(): array
    {
        $fieldLayout = $this->getFieldLayout();

        if (!$fieldLayout) {
            return [];
        }

        $labels = [];

        foreach ($fieldLayout->getTabs() as $tab) {
            $labels[] = (string)$tab->name;
        }

        return $labels;
    }

    public function getExtraTabLabels(): array
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::getExtraTabLabels', 'Link `getExtraTabLabels()` has been deprecated. Use `getTabLabels()` instead.');

        $labels = $this->getTabLabels();

        return count($labels) < 2 ? [] : array_values(array_slice($labels, 1));
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        // Needed to override the element title
        if (isset($values['linkTitle'])) {
            $this->title = $values['linkTitle'];
        }

        // Prevent setting values retained when removed from field layout. Otherwise, stale values
        if ($fieldLayout = $this->getFieldLayout()) {
            $nativeFields = ArrayHelper::getColumn($fieldLayout->getAvailableNativeFields(), 'attribute');

            // Remove any native field (attribute) that aren't included in the field layout
            if (!$this->_hydratingFromInstance) {
                foreach ($nativeFields as $nativeField) {
                    if (!$fieldLayout->isFieldIncluded($nativeField)) {
                        if (array_key_exists($nativeField, $values)) {
                            unset($values[$nativeField]);
                        }
                    }
                }
            }

            // Partial native-attribute updates must not replace omitted custom fields.
            if (array_key_exists('fields', $values) && (!$safeOnly || $this->isAttributeSafe('fields'))) {
                $fieldContent = [];

                // Convert from layoutElementUid saved to the database to handle.
                foreach ($values['fields'] ?? [] as $handle => $fieldValue) {
                    if (str_contains($handle, '-')) {
                        $fieldContent[$handle] = $fieldValue;

                        foreach ($fieldLayout->getCustomFields() as $field) {
                            if ($field->layoutElement && $field->layoutElement->uid === $handle) {
                                unset($fieldContent[$handle]);
                                $fieldContent[$field->handle] = $fieldValue;
                            }
                        }
                    } else {
                        $fieldContent[$handle] = $fieldValue;
                    }
                }

                // Reset omitted values on a replacement, including Craft's normalized
                // field cache. Unknown fields remain opaque in the stored payload.
                foreach ($fieldLayout->getCustomFields() as $field) {
                    parent::setFieldValue($field->handle, $fieldContent[$field->handle] ?? null);
                }

                $values['fields'] = $fieldContent;
            }
        }

        // Check if new window is disabled at the field level
        if ($this->field && !$this->field->newWindow) {
            $values['newWindow'] = false;
        }

        if (array_key_exists('linkValue', $values)) {
            $linkValue = $values['linkValue'];

            if ($linkValue === '' || $linkValue === []) {
                $values['linkValue'] = null;
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function setFieldValue(string $fieldHandle, mixed $value): void
    {
        parent::setFieldValue($fieldHandle, $value);

        // Craft's custom-field behaviour and Hyper's stored JSON must share edits.
        // Keep raw values here so an unrelated save does not normalize other fields.
        $this->fields[$fieldHandle] = $value;
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
        return $this->getResolvedNewWindow();
    }

    public function getResolvedNewWindow(): bool
    {
        if ($this->newWindow !== null) {
            return $this->newWindow;
        }

        if ($this->field && $this->field->newWindow) {
            return $this->field->defaultNewWindow;
        }

        return false;
    }

    public function getLinkText(): ?string
    {
        if ($this->linkText !== null && $this->linkText !== '') {
            return $this->linkText;
        }

        return $this->getLinkTextLayoutDefault();
    }

    public function getCustomLinkText(): ?string
    {
        if ($this->linkText === null || $this->linkText === '') {
            return null;
        }

        return $this->linkText;
    }

    public function getLinkUrl(): ?string
    {
        $linkValue = $this->linkValue;

        // Some malformed payloads can arrive as arrays (for example from element-select style data).
        // Normalize to a scalar for non-element links to avoid PHP "Array to string conversion" warnings.
        if (is_array($linkValue)) {
            if (array_is_list($linkValue)) {
                $linkValue = $linkValue[0] ?? null;
            } else {
                $linkValue = $linkValue['url'] ?? null;
            }
        }

        if (!is_scalar($linkValue) && $linkValue !== null) {
            return null;
        }

        if ($linkValue === null || $linkValue === '') {
            return null;
        }

        // Link destinations are authored content. Expanding configuration variables
        // here would expose server-side values in rendered URLs and API responses.
        return (string)$linkValue;
    }

    public function getHtml(): ?Markup
    {
        return null;
    }

    public function getIframeSrc(): ?string
    {
        return null;
    }

    public function getEmbedProviderName(): ?string
    {
        return null;
    }

    public function getEmbedImage(): ?string
    {
        return null;
    }

    public function getSerializedLayoutFields(?\craft\models\GqlSchema $schema = null): array
    {
        $fieldLayout = $this->getFieldLayout();

        if (!$fieldLayout) {
            return [];
        }

        if ($schema === null) {
            try {
                $schema = Craft::$app->getGql()->getActiveSchema();
            } catch (\Throwable) {
                $schema = null;
            }
        }

        $serialized = [];

        foreach ($fieldLayout->getCustomFields() as $field) {
            // Convenience `fields` bag must honour the same schema gate as typed GQL fields (A09).
            if ($schema && !$field->includeInGqlSchema($schema)) {
                continue;
            }

            $serialized[$field->handle] = $field->serializeValue($this->getFieldValue($field->handle), $this);
        }

        return $serialized;
    }

    public function getUrl(): ?string
    {
        $linkUrl = $this->getLinkUrl();

        // A suffix augments a destination; it cannot revive a missing/disabled target.
        if ($linkUrl === null || trim($linkUrl) === '') {
            return null;
        }

        $url = trim($this->getUrlPrefix() . $linkUrl . $this->getUrlSuffix());

        // Render path enforces the same scheme policy as validation (Astra A02).
        $extra = Hyper::$plugin?->getSettings()->allowedUriSchemes ?? [];

        return UrlSafety::isAllowedUrl($url, $extra) ? $url : null;
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

        $text = $this->getLinkText();

        return $text !== null && $text !== '' ? $text : ($defaultText !== '' ? $defaultText : null);
    }

    public function getTarget(): ?string
    {
        return $this->getResolvedNewWindow() ? '_blank' : null;
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
            if (!is_array($value) || !is_string($value['attribute'] ?? null)) {
                continue;
            }

            $name = trim($value['attribute']);

            // Drop event-handler / malformed names — Yii interpolates attribute names raw.
            if (!UrlSafety::isSafeAttributeName($name)) {
                continue;
            }

            $attributeValue = $value['value'] ?? '';

            // Author values come from text cells. Yii expands structured data/aria
            // values into new attribute names, outside the name check above.
            if (!is_scalar($attributeValue) && $attributeValue !== null) {
                continue;
            }

            // Author attributes can override the native destination. Enforce the same
            // policy here; caller-supplied template attributes remain explicitly trusted.
            if (in_array(strtolower($name), ['href', 'xlink:href', 'ping'], true)) {
                $extra = Hyper::$plugin?->getSettings()->allowedUriSchemes ?? [];

                if (!is_string($attributeValue) || !UrlSafety::isAllowedUrl($attributeValue, $extra)) {
                    continue;
                }
            }

            $attributes[$name] = $attributeValue;
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

        // Stored / author strings are escaped; template-supplied Markup stays trusted (DECISIONS 2026-09-08).
        if ($text !== null && !$text instanceof Markup) {
            $text = Html::encode((string)$text);
        }

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

        if (($title = $this->getLinkTitle()) !== null && $title !== '') {
            $attr['title'] = $title;
        }

        if (($ariaLabel = $this->getAriaLabel()) !== null && $ariaLabel !== '') {
            $attr['aria-label'] = $ariaLabel;
        }

        if ($this->getNewWindow()) {
            $attr['target'] = '_blank';
            $attr['rel'] = 'noopener noreferrer';
        }

        // Merge attributes in a specific order to allow template-provided attributes to override everything.
        // The order should be built-in (above), Custom Attribute field settings, and template-provided attributes.
        $attributes = $this->_mergeAttributes($attr, $this->getCustomAttributes(), $attributes);
        $attributes = array_filter($attributes, static fn(mixed $value): bool => $value !== null && $value !== false && $value !== '' && $value !== []);

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

    protected function getLinkTextLayoutDefault(): ?string
    {
        $fieldLayout = $this->getFieldLayout();

        if (!$fieldLayout || !$fieldLayout->isFieldIncluded('linkText')) {
            return null;
        }

        $layoutElement = $fieldLayout->getField('linkText');

        if (!$layoutElement instanceof LinkTextField) {
            return null;
        }

        $default = $layoutElement->defaultValue;

        return ($default !== null && $default !== '') ? $default : null;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['label', 'handle', 'enabled', 'newWindow', 'linkValue', 'linkText', 'ariaLabel', 'urlSuffix', 'linkTitle', 'classes', 'customAttributes', 'uid'], 'safe'];

        // Validation for only when saving Hyper fields and their settings
        $rules[] = [['label', 'handle'], 'required', 'on' => [self::SCENARIO_SETTINGS]];

        $fieldLayout = $this->getFieldLayout();

        if ($this->isFieldRequired && (!$fieldLayout || $fieldLayout->isFieldIncluded('linkValue'))) {
            $rules[] = [['linkValue'], 'required', 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]];
        }

        if ($fieldLayout) {
            foreach ($fieldLayout->getTabs() as $tab) {
                foreach ($tab->getElements() as $layoutElement) {
                    if ($layoutElement instanceof BaseNativeField && $layoutElement->required) {
                        $when = null;

                        if ($layoutElement->attribute === 'linkText') {
                            $when = fn(self $model): bool => $model->hasLinkValue();
                        }

                        $rule = [
                            [$layoutElement->attribute],
                            'required',
                            'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE],
                        ];

                        if ($when) {
                            $rule['when'] = $when;
                        }

                        $rules[] = $rule;
                    }

                    // Character limit from Link Text (and other text natives) FLD settings.
                    if (
                        $layoutElement instanceof TextField
                        && $layoutElement->maxlength
                        && $layoutElement->attribute
                    ) {
                        $rules[] = [
                            [$layoutElement->attribute],
                            'string',
                            'max' => (int)$layoutElement->maxlength,
                            'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE],
                        ];
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
