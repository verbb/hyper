<?php
namespace verbb\hyper\fields;

use verbb\hyper\Hyper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\base\Link;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\base\LinkTypeSettings;
use verbb\hyper\gql\interfaces\LinkInterface as GqlLinkInterface;
use verbb\hyper\links as linkTypes;
use verbb\hyper\helpers\Plugin;
use verbb\hyper\helpers\StringHelper;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkInstance;
use verbb\hyper\models\LinkTypeDefinition;
use verbb\hyper\services\LinkTypeConfigs;
use verbb\hyper\services\Links;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\EagerLoadingFieldInterface;
use craft\base\Field;
use craft\base\MergeableFieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\conditions\EmptyFieldConditionRule;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\Gql;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\ProjectConfig;
use craft\models\FieldLayout;
use craft\validators\ArrayValidator;
use craft\web\View;
use craft\base\PreviewableFieldInterface;
use craft\base\ThumbableFieldInterface;

use yii\db\Schema;

use Exception;
use Throwable;

use GraphQL\Type\Definition\Type;

class HyperField extends Field implements ThumbableFieldInterface, MergeableFieldInterface, PreviewableFieldInterface, EagerLoadingFieldInterface
{
    // Constants
    // =========================================================================

    public const VIEW_MODE_BLOCKS = 'blocks';
    public const VIEW_MODE_CARDS = 'cards';
    public const EDITOR_MODE_EXPANDED = self::VIEW_MODE_BLOCKS;
    public const EDITOR_MODE_CARDS = self::VIEW_MODE_CARDS;


    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Hyper');
    }

    public static function icon(): string
    {
        return '@verbb/hyper/icon-mask.svg';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', LinkCollection::class);
    }

    public static function normalizeViewMode(?string $mode): string
    {
        return match ($mode) {
            self::VIEW_MODE_CARDS => self::VIEW_MODE_CARDS,
            default => self::VIEW_MODE_BLOCKS,
        };
    }

    public static function normalizeEditorMode(?string $mode): string
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::normalizeEditorMode', 'Field `normalizeEditorMode()` has been deprecated. Use `normalizeViewMode()` instead.');

        return self::normalizeViewMode($mode);
    }


    // Properties
    // =========================================================================

    public ?string $defaultLinkType = 'url';
    public bool $newWindow = true;
    public bool $defaultNewWindow = false;
    public bool $multipleLinks = false;
    public ?int $minLinks = null;
    public ?int $maxLinks = null;
    public bool $enableBulkAdd = false;
    public ?int $fieldLayoutId = null;
    public array $migrationData = [];
    public string $viewMode = self::VIEW_MODE_BLOCKS;
    public string $linkTypeConfig = LinkTypeConfigs::DEFAULT_HANDLE;

    private bool $_isStatic = false;
    private array $_linkTypes = [];
    private array $_serializedLinkTypes = [];
    private ?array $_linkTypeFields = null;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        if (isset($config['linkTypes']) && $config['linkTypes'] === '') {
            $config['linkTypes'] = [];
        }

        // Legacy/malformed POST payloads may still send `types` instead of `linkTypes`.
        if (isset($config['types']) && !isset($config['linkTypes'])) {
            $config['linkTypes'] = $config['types'];
        }

        // Canonicalize stock handles from early v3 project config while preserving
        // author-owned custom handles. Keep defaultLinkType pointed at the renamed type.
        if (isset($config['linkTypes']) && is_array($config['linkTypes'])) {
            $originalLinkTypes = $config['linkTypes'];
            $config['linkTypes'] = LinkTypeConfigs::normalizeLegacyStockHandles($originalLinkTypes);

            foreach ($originalLinkTypes as $key => $original) {
                $normalized = $config['linkTypes'][$key] ?? null;

                if (
                    is_array($original) &&
                    is_array($normalized) &&
                    ($config['defaultLinkType'] ?? null) === ($original['handle'] ?? null) &&
                    isset($normalized['handle'])
                ) {
                    $config['defaultLinkType'] = $normalized['handle'];
                    break;
                }
            }
        }

        // Rename editorMode → viewMode (expanded → blocks).
        if (array_key_exists('editorMode', $config) && !array_key_exists('viewMode', $config)) {
            $config['viewMode'] = $config['editorMode'];
        }

        unset($config['columnType'], $config['types'], $config['applyPresetUid'], $config['editorMode']);

        if (array_key_exists('viewMode', $config)) {
            $config['viewMode'] = self::normalizeViewMode(
                is_string($config['viewMode']) ? $config['viewMode'] : null,
            );
        }

        // Fold the short-lived lightswitch settings into the single config selector.
        if (!array_key_exists('linkTypeConfig', $config)) {
            $isCustom = array_key_exists('customLinkTypes', $config)
                ? (bool)$config['customLinkTypes']
                : (array_key_exists('usePluginLinkTypes', $config)
                    ? !((bool)$config['usePluginLinkTypes'])
                    : !empty($config['linkTypes'] ?? []));

            $config['linkTypeConfig'] = $isCustom
                ? LinkTypeConfigs::CUSTOM_HANDLE
                : LinkTypeConfigs::DEFAULT_HANDLE;
        }

        unset($config['customLinkTypes'], $config['usePluginLinkTypes']);

        parent::__construct($config);
    }

    public function init(): void
    {
        parent::init();

        $this->viewMode = self::normalizeViewMode($this->viewMode);
    }

    public function getSettings(): array
    {
        $settings = parent::getSettings();

        $settings['viewMode'] = self::normalizeViewMode(
            isset($settings['viewMode']) && is_string($settings['viewMode'])
                ? $settings['viewMode']
                : (isset($settings['editorMode']) && is_string($settings['editorMode'])
                    ? $settings['editorMode']
                    : null),
        );
        unset($settings['editorMode']);

        $settings['linkTypeConfig'] = $this->linkTypeConfig ?: LinkTypeConfigs::DEFAULT_HANDLE;
        unset($settings['customLinkTypes'], $settings['usePluginLinkTypes']);

        // Shared config: store no private link types — the named config is the source of truth.
        if (!$this->hasCustomLinkTypes()) {
            $settings['linkTypes'] = [];

            return $settings;
        }

        // Serialize the link types as arrays instead of arrays of Link classes
        $settings['linkTypes'] = array_map(function($linkType) {
            return $linkType->getSettingsConfigForDb();
        }, $this->getLinkTypes());

        // Override this behaviour when using the field merge tools so that it can effectively compare Hyper field
        // due to their field layouts for link types not returning as the same.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $route = Craft::$app->getRequest()->getParams()[0] ?? null;

            if ($route === 'fields/auto-merge' || $route === 'fields/merge') {
                foreach ($settings['linkTypes'] as $linkTypeKey => &$linkType) {
                    unset($linkType['layoutUid']);

                    if (isset($linkType['layoutConfig']['tabs']) && is_array($linkType['layoutConfig']['tabs'])) {
                        foreach ($linkType['layoutConfig']['tabs'] as $layoutTabKey => &$layoutTab) {
                            unset($layoutTab['uid']);

                            if (isset($layoutTab['elements']) && is_array($layoutTab['elements'])) {
                                foreach ($layoutTab['elements'] as $layoutElementKey => &$layoutElement) {
                                    unset($layoutElement['uid']);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $settings;
    }

    public function validateLinkTypes(): void
    {
        $linkTypes = $this->getLinkTypes();

        // Ensure there is at least one enabled link type
        if (!ArrayHelper::getColumn($linkTypes, 'enabled')) {
            $this->addError('linkTypes', Craft::t('hyper', 'You must enable at least one link type.'));
        }

        // Handles are the link type's public identity (GraphQL type names, programmatic
        // content payloads, `getLinkTypeByHandle()`), so they must be unique within the
        // field. The client keeps row keys unique, but this is the authoritative guard.
        $seen = [];

        foreach ($linkTypes as $linkType) {
            $handle = (string)$linkType->handle;

            if ($handle === '') {
                continue;
            }

            if (isset($seen[$handle])) {
                $this->addError('linkTypes', Craft::t('hyper', 'Two link types have the same handle “{handle}”. Handles must be unique.', [
                    'handle' => $handle,
                ]));
            }

            $seen[$handle] = true;
        }
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        $isValueEmpty = parent::isValueEmpty($value, $element);

        if ($value instanceof LinkCollection) {
            $isValueEmpty = $isValueEmpty || $value->isEmpty();
        }

        return $isValueEmpty;
    }

    public function getSettingsHtml(): ?string
    {
        $view = Craft::$app->getView();

        $inputNamePrefix = $view->getNamespace();
        $inputIdPrefix = Html::id($inputNamePrefix);

        // Register Hyper CP assets; vanilla TS auto-mount via hyper.ts.
        Plugin::registerFieldAssets();

        // Craft Matrix view-mode icons (blocks.svg / cards.svg).
        $cpBundle = $view->registerAssetBundle(\craft\web\assets\cp\CpAsset::class);

        // Get the link type settings (plugin defaults when attached; field-owned when detached)
        $linkTypes = $this->_getLinkTypeSettings();

        // Return a list of all registered link types for adding new ones
        $registeredLinkTypes = array_map(function($linkTypeClass) {
            return [
                'label' => $linkTypeClass::displayName(),
                'value' => $linkTypeClass,
            ];
        }, Hyper::$plugin->getLinks()->getAllLinkTypes());

        return $view->renderTemplate('hyper/field/settings', [
            'field' => $this,
            'inputNamePrefix' => $inputNamePrefix,
            'inputIdPrefix' => $inputIdPrefix,
            'namespacedName' => $view->namespaceInputName('__PREFIX__'),
            'linkTypes' => $linkTypes,
            'registeredLinkTypes' => $registeredLinkTypes,
            'linkTypeConfigs' => Hyper::$plugin->getLinkTypeConfigs()->getAllConfigs(),
            'baseIconsUrl' => $cpBundle->baseUrl . '/images/view-modes',
            'settingsConfig' => [
                'fieldId' => $this->id,
                'registeredLinkTypes' => $registeredLinkTypes,
                'namespacedName' => $view->namespaceInputName('__PREFIX__'),
                'namespacedId' => $view->namespaceInputId('__PREFIX__'),
                'linkTypeTemplates' => array_map(static function(array $linkType): array {
                    return [
                        'type' => $linkType['type'],
                        'displayName' => $linkType['displayName'] ?? null,
                        'htmlTemplate' => $linkType['htmlTemplate'] ?? null,
                        'jsTemplate' => $linkType['jsTemplate'] ?? null,
                        'layoutConfig' => $linkType['layoutConfig'] ?? null,
                        'layoutUid' => $linkType['layoutUid'] ?? null,
                    ];
                }, $linkTypes),
            ],

            // Required placeholder to work with nested namespace (Matrix)
            'namespacedName' => $view->namespaceInputName('__PREFIX__'),
            'namespacedId' => $view->namespaceInputId('__PREFIX__'),
        ]);
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!($value instanceof LinkCollection) || $value->isEmpty()) {
            return '';
        }

        $links = $value->getLinks();
        $first = $links[0] ?? null;

        if (!$first) {
            return '';
        }

        $label = $first->getText() ?: $first->getUrl() ?: Craft::t('hyper', 'Link');
        $html = Html::encode((string)$label);

        // Multi-link Matrix/card preview: show first label plus remaining count.
        $extra = count($links) - 1;

        if ($extra > 0) {
            $html .= ' ' . Html::tag('span', Craft::t('hyper', 'and {count} other {count, plural, =1{link} other{links}}', [
                'count' => $extra,
            ]), ['class' => 'light']);
        }

        return $html;
    }

    public function getThumbHtml(mixed $value, ElementInterface $element, int $size): ?string
    {
        return $value ? $this->_renderLink($value) : '';
    }

    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        return Html::tag('a', Craft::t('app', 'link/to/something'), [
            'href' => '#',
            'rel' => 'noopener',
            'target' => '_blank',
            'class' => 'go',
            'title' => Craft::t('app', 'Visit webpage'),
        ]);
    }

    public function normalizeValue(mixed $value, ElementInterface $element = null): mixed
    {
        if ($value instanceof LinkCollection) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value)) {
            $value = [];
        }

        $collection = new LinkCollection($this, $value, $element);

        if ($element && Hyper::$plugin->getMultisiteLinks()->shouldLocalizePropagatedValue($this, $element)) {
            $collection = Hyper::$plugin->getMultisiteLinks()->localizeLinkCollection($this, $collection, $element);
        }

        return $collection;
    }

    public function propagateValue(ElementInterface $from, ElementInterface $to): void
    {
        $value = $from->getFieldValue($this->handle);

        if ($value instanceof LinkCollection) {
            $value = Hyper::$plugin->getMultisiteLinks()->localizeLinkCollection(
                $this,
                $value,
                $to,
                $from->siteId,
            );
        }

        $to->setFieldValue($this->handle, $value);
    }

    public function serializeValue(mixed $value, ElementInterface $element = null): mixed
    {
        if ($value instanceof LinkCollection) {
            $value = $value->serializeValues($element);
            $value = self::_preserveScalarLinkValues($value);

            return Json::decode(Json::encode($value));
        }

        return $value;
    }

    public function getElementConditionRuleType(): array|string|null
    {
        return EmptyFieldConditionRule::class;
    }

    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        $this->setIsStatic();

        return $this->getInputHtml($value, $element);
    }

    public function setIsStatic(): void
    {
        $this->_isStatic = true;
    }

    public function beforeSave(bool $isNew): bool
    {
        // Custom rows are posted with the field; programmatic switches get stock defaults as a fallback.
        if ($this->hasCustomLinkTypes() && empty($this->_serializedLinkTypes)) {
            $this->setLinkTypes(Hyper::$plugin->getLinkTypeConfigs()->createDetachedCopy());
        }

        // Use config: drop private link types — named config is authoritative.
        if (!$this->hasCustomLinkTypes()) {
            $this->setLinkTypes([]);
        }

        if (!$this->linkTypeConfig) {
            $this->linkTypeConfig = LinkTypeConfigs::DEFAULT_HANDLE;
        }

        if (!parent::beforeSave($isNew)) {
            return false;
        }

        // Save each link type correctly and validate
        $hasErrors = false;

        foreach ($this->getLinkTypes() as $linkType) {
            // Set the correct scenario for the link type (an "element") to validate only field settings rules
            $linkType->setScenario(Link::SCENARIO_SETTINGS);

            if (!$linkType->validate()) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $this->addError('linkTypes', Craft::t('hyper', 'Correct the above errors.'));

            return false;
        }

        // Shared-default fields do not own layouts — plugin defaults PC handlers persist those.
        if (!$this->hasCustomLinkTypes()) {
            return true;
        }

        // Any fields not in the global scope won't trigger a PC change event. Go manual.
        if ($this->context !== 'global') {
            Hyper::$plugin->getService()->saveField(
                array_map(static fn(LinkTypeDefinition $definition): array => $definition->toSettingsArray(), $this->getLinkTypeDefinitions())
            );
        }

        return true;
    }

    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        $value = $element->getFieldValue($this->handle);

        if ($value instanceof LinkCollection) {
            Hyper::$plugin->getLinkRelations()->syncFromLinkCollection($this, $element, $value);
            Hyper::$plugin->getMultisiteLinks()->propagateLinkStructure($this, $element, $value);
        }

        parent::afterElementSave($element, $isNew);
    }

    public function getLinkTypeByHandle(?string $handle): ?LinkInterface
    {
        if (!$handle) {
            $prototype = $this->getLinkTypes()[0] ?? null;
        } else {
            $prototype = ArrayHelper::firstWhere($this->getLinkTypes(), 'handle', $handle);

            // Back-compat: content saved before short type keys used the verbose
            // `default-<kebab-fqcn>` handle (e.g. `default-verbb-hyper-links-url`). Map any
            // such stored handle onto the matching built-in type so existing links still resolve.
            if (!$prototype && str_starts_with($handle, 'default-')) {
                $prototype = ArrayHelper::firstWhere(
                    $this->getLinkTypes(),
                    fn(LinkInterface $linkType): bool => 'default-' . StringHelper::toKebabCase($linkType::class) === $handle,
                );
            }
        }

        if (!$prototype) {
            return null;
        }

        $link = clone $prototype;
        $link->field = $this;

        return $link;
    }

    public function getLinkTypeDefinitions(): array
    {
        if (!$this->hasCustomLinkTypes()) {
            return Hyper::$plugin->getLinkTypeConfigs()->getLinkTypeDefinitions($this->linkTypeConfig);
        }

        $definitions = [];

        foreach ($this->_serializedLinkTypes as $config) {
            if ($config instanceof LinkInterface) {
                $definitions[] = LinkTypeDefinition::fromLinkType($config);
                continue;
            }

            if (is_array($config)) {
                $definitions[] = LinkTypeDefinition::fromSettingsArray($config);
            }
        }

        if ($definitions) {
            return $definitions;
        }

        foreach ($this->getLinkTypes() as $linkType) {
            $definitions[] = LinkTypeDefinition::fromLinkType($linkType);
        }

        return $definitions;
    }

    public function getLinkTypeSettingsOwners(): array
    {
        return array_map(
            static fn(LinkTypeDefinition $definition): LinkTypeSettings => LinkTypeSettings::fromDefinition($definition),
            $this->getLinkTypeDefinitions(),
        );
    }

    public function getLinkTypeDefinitionByHandle(?string $handle): ?LinkTypeDefinition
    {
        if (!$handle) {
            return null;
        }

        foreach ($this->getLinkTypeDefinitions() as $definition) {
            if ($definition->handle === $handle) {
                return $definition;
            }
        }

        return null;
    }

    public function hasCustomLinkTypes(): bool
    {
        return $this->linkTypeConfig === LinkTypeConfigs::CUSTOM_HANDLE;
    }

    public function enableCustomLinkTypes(): void
    {
        $sourceHandle = $this->hasCustomLinkTypes()
            ? LinkTypeConfigs::DEFAULT_HANDLE
            : $this->linkTypeConfig;

        $this->setLinkTypes(Hyper::$plugin->getLinkTypeConfigs()->createDetachedCopy($sourceHandle));
        $this->linkTypeConfig = LinkTypeConfigs::CUSTOM_HANDLE;
    }

    public function useLinkTypeConfig(?string $handle = null): void
    {
        $this->linkTypeConfig = $handle && $handle !== LinkTypeConfigs::CUSTOM_HANDLE
            ? $handle
            : LinkTypeConfigs::DEFAULT_HANDLE;
        $this->setLinkTypes([]);
    }

    public function usePluginLinkTypeDefaults(): void
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::usePluginLinkTypeDefaults', 'Field `usePluginLinkTypeDefaults()` has been deprecated. Use `useLinkTypeConfig()` instead.');

        $this->useLinkTypeConfig();
    }

    public function detachFromPluginLinkTypes(): void
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::detachFromPluginLinkTypes', 'Field `detachFromPluginLinkTypes()` has been deprecated. Use `enableCustomLinkTypes()` instead.');

        $this->enableCustomLinkTypes();
    }

    public function attachToPluginLinkTypes(): void
    {
        // Deprecated in 3.0.0
        Craft::$app->getDeprecator()->log(static::class . '::attachToPluginLinkTypes', 'Field `attachToPluginLinkTypes()` has been deprecated. Use `useLinkTypeConfig()` instead.');

        $this->useLinkTypeConfig();
    }

    public function modifyElementsQuery(ElementQueryInterface $query, mixed $value): void
    {
        Hyper::$plugin->getLinkedElementEagerLoader()->parseWithPaths($query);
    }

    public function getEagerLoadingMap(array $sourceElements): array|null|false
    {
        // Linked targets hydrate via LinkRelations batch priming, not Craft-native maps.
        return null;
    }

    public function getEagerLoadingGqlConditions(): ?array
    {
        return null;
    }

    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::nonNull(Type::listOf(GqlLinkInterface::getType($this))),
        ];
    }

    public function getElementValidationRules(): array
    {
        return [
            [
                'validateBlocks',
                'on' => [Element::SCENARIO_ESSENTIALS, Element::SCENARIO_DEFAULT, Element::SCENARIO_LIVE],
                'skipOnEmpty' => false,
            ],
        ];
    }

    public function validateBlocks(ElementInterface $element): void
    {
        $scenario = $element->getScenario();

        if ($scenario !== Element::SCENARIO_LIVE) {
            return;
        }

        $links = $element->getFieldValue($this->handle);

        foreach ($links as $i => $link) {
            $link->setScenario($scenario);

            // Set a flag whether the Hyper field itself is required
            $link->isFieldRequired = $this->required;

            if (!$link->validate()) {
                $element->addModelErrors($link, "{$this->handle}[{$i}]");
            }
        }

        if ($this->multipleLinks && ($this->minLinks || $this->maxLinks)) {
            $arrayValidator = new ArrayValidator([
                'min' => $this->minLinks ?: null,
                'max' => $this->maxLinks ?: null,
                'tooFew' => $this->minLinks ? Craft::t('app', '{attribute} should contain at least {min, number} {min, plural, one{link} other{links}}.', [
                    'attribute' => Craft::t('site', $this->name),
                    'min' => $this->minLinks, // Need to pass this in now
                ]) : null,
                'tooMany' => $this->maxLinks ? Craft::t('app', '{attribute} should contain at most {max, number} {max, plural, one{link} other{links}}.', [
                    'attribute' => Craft::t('site', $this->name),
                    'max' => $this->maxLinks, // Need to pass this in now
                ]) : null,
                'skipOnEmpty' => false,
            ]);

            if (!$arrayValidator->validate($links, $error)) {
                $element->addError($this->handle, $error);
            }
        }
    }

    public function getLinkTypes(): array
    {
        if ($this->_linkTypes) {
            return $this->_linkTypes;
        }

        // Live-share selected config when not custom (clones prevent shared-cache mutation).
        if (!$this->hasCustomLinkTypes()) {
            foreach (Hyper::$plugin->getLinkTypeConfigs()->getLinkTypes($this->linkTypeConfig) as $sortOrder => $linkType) {
                $clone = clone $linkType;

                if ($clone instanceof Link) {
                    $clone->clearContentState();
                    $clone->setScenario(Link::SCENARIO_SETTINGS);
                }

                $this->_linkTypes[$sortOrder] = $clone;
            }

            return $this->_linkTypes;
        }

        $registeredLinkTypes = Hyper::$plugin->getLinks()->getAllLinkTypes();

        foreach ($this->_serializedLinkTypes as $key => $config) {
            // Check if the saved link type is still registered. Be sure to check if this is an early
            // initialization where no registered link types are available - that's okay.
            if ($registeredLinkTypes && !in_array($config['type'], $registeredLinkTypes)) {
                continue;
            }

            $sortOrder = ArrayHelper::remove($config, 'sortOrder', $key);
            
            if ($config instanceof LinkInterface) {
                $linkType = $config;

                if ($linkType instanceof Link) {
                    $linkType->clearContentState();
                    $linkType->setScenario(Link::SCENARIO_SETTINGS);
                }
            } else {
                // Some extra handling here when setting from the POST.
                $config['layoutConfig'] = $this->_normalizeLayoutConfig($config);
                $linkType = Hyper::$plugin->getLinks()->createSettingsPrototype($config);
            }

            // Set up the field layout config - it'll be saved later
            if (!$linkType->layoutConfig) {
                $linkType->layoutConfig = $linkType::getDefaultFieldLayout()->getConfig();
            }

            // Generate a layout UID if not already set
            if (!$linkType->layoutUid) {
                $linkType->layoutUid = StringHelper::UUID();
            }

            $this->_linkTypes[$sortOrder] = $linkType;
        }

        return $this->_linkTypes;
    }

    public function getLinkTypeFields(?array $typeHandles = null): array
    {
        if (!isset($this->_linkTypeFields)) {
            $this->_linkTypeFields = [];

            if (!empty($linkTypes = $this->getLinkTypes())) {
                
                $fieldColumnPrefix = 'field_';
                
                foreach ($linkTypes as $linkType) {
                    $fields = $linkType->getCustomFields();

                    foreach ($fields as $field) {
                        $this->_linkTypeFields[$linkType->handle][] = $field;
                    }
                }
            }
        }

        $fields = [];

        foreach ($this->_linkTypeFields as $linkTypeHandle => $linkTypeFields) {
            if ($typeHandles === null || in_array($linkTypeHandle, $typeHandles)) {
                array_push($fields, ...$linkTypeFields);
            }
        }

        $fields = array_unique($fields, SORT_REGULAR);

        return $fields;
    }

    public function setLinkTypes(array $linkTypes): void
    {
        // Set the raw, serialized link types, which are created as objects later. Doing that too early
        // leads to a whole ream of issues, so do the work in the getter.
        $this->_serializedLinkTypes = $linkTypes;
        $this->_linkTypes = [];
        $this->_linkTypeFields = null;
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['minLinks', 'maxLinks'], 'integer', 'min' => 0];
        $rules[] = [['enableBulkAdd'], 'boolean'];
        $rules[] = ['linkTypes', 'validateLinkTypes'];
        $rules[] = [['viewMode'], 'in', 'range' => [
            self::VIEW_MODE_BLOCKS,
            self::VIEW_MODE_CARDS,
        ]];

        return $rules;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $view = Craft::$app->getView();
        $id = Html::id($this->handle);

        // Ensure that a valid default link type is set, just in case. Otherwise select the first.
        $enabledLinkTypes = array_values(ArrayHelper::where($this->getLinkTypes(), 'enabled'));
        $defaultLinkTypeObject = ArrayHelper::where($enabledLinkTypes, 'handle', $this->defaultLinkType);

        if (!$defaultLinkTypeObject) {
            $this->defaultLinkType = $enabledLinkTypes[0]->handle ?? null;
        }

        // Cache the placeholder key for the fields' JS. Because we're caching the block type HTML/JS
        // we also need to cache the placeholder key to match that cached data.
        $placeholderKey = Hyper::$plugin->getCache()->getOrSet($this->_getCacheKey('placeholderKey'), function() {
            return StringHelper::randomString(10);
        });

        $settings = [
            'fieldId' => $this->id,
            'handle' => $this->handle,
            // CP Advanced-tab relation pickers need the owner site.
            'siteId' => $element?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id,
            'defaultLinkType' => $this->defaultLinkType,
            'defaultNewWindow' => $this->defaultNewWindow,
            'newWindow' => $this->newWindow,
            'multipleLinks' => $this->multipleLinks,
            'minLinks' => $this->minLinks,
            'maxLinks' => $this->maxLinks,
            'viewMode' => self::normalizeViewMode($this->viewMode),
            'namespacedName' => $view->namespaceInputName($this->handle),
            'namespacedId' => $view->namespaceInputId($this->handle),
            'isStatic' => $this->_isStatic,
            'placeholderKey' => $placeholderKey,
        ];

        // Prepare the link types and HTML for fields
        $linkTypeInfo = $this->_getLinkTypeInfoForInput($element, $placeholderKey);
        $settings['linkTypes'] = $linkTypeInfo['linkTypes'] ?? [];
        $settings['js'] = $linkTypeInfo['js'] ?? [];

        // Prepare the link element values for the field, including pre-rendered HTML
        $value = $this->_getLinksForInput($value, $placeholderKey, $element);

        $linksForTwig = [];
        $storeValue = [];
        $initialValue = [];

        foreach ($value as $index => $linkData) {
            $handle = $linkData['handle'];
            $linkId = (string)$linkData['id'];
            $html = $linkData['html'][$handle] ?? '';
            $js = $linkData['js'][$handle] ?? '';
            $serialized = $linkData['serialized'] ?? [];

            unset($linkData['html'], $linkData['js'], $linkData['serialized']);

            // Hidden store uses persist shape only; initialValue keeps link id for portal matching.
            $storeValue[] = $serialized;
            $initialValue[] = array_merge(['id' => $linkId], $serialized);

            $linkType = $this->getLinkTypeByHandle($handle);
            $tabLabels = $linkType?->getTabLabels() ?? [];
            $tabCount = count($tabLabels) ?: ($linkType?->getTabCount() ?? 0);
            // Header icon when field enables new window and this type’s layout does not own it.
            $newWindowInLayout = (bool)($linkType?->getFieldLayout()?->isFieldIncluded('newWindow'));

            $linksForTwig[] = [
                'id' => $linkId,
                // Stored content handle — kept as-is for the store / serialize / portal so legacy
                // `default-<fqcn>` content is not rewritten on unrelated saves (content retention).
                'handle' => $handle,
                // Resolved canonical handle + display label for the CP header/type dropdown only.
                // Legacy content resolves to the short type key so the UI shows "URL" (not the FQCN
                // handle) and marks the current type, without touching the persisted value.
                'typeHandle' => $linkType?->handle ?? $handle,
                'label' => $linkType ? Craft::t('hyper', (string)$linkType->label) : $handle,
                'newWindow' => !empty($linkData['newWindow']),
                'showHeaderNewWindow' => $this->newWindow && !$newWindowInLayout,
                'bodyHtml' => $this->_parseBlockPlaceholder($html, $linkId, $placeholderKey),
                'js' => $this->_namespaceDeferredFieldPayload(
                    $this->_parseBlockPlaceholder($js, $linkId, $placeholderKey),
                ),
                'tabCount' => $tabCount,
                'tabLabels' => $tabLabels,
                'index' => $index,
            ];
        }

        $linkTypeTemplates = [];

        foreach ($settings['linkTypes'] as $linkType) {
            $handle = $linkType['handle'];
            $resolved = $this->getLinkTypeByHandle($handle);
            $tabLabels = $resolved?->getTabLabels() ?? [];
            $newWindowInLayout = (bool)($resolved?->getFieldLayout()?->isFieldIncluded('newWindow'));

            $linkTypeTemplates[] = [
                'handle' => $handle,
                'label' => $linkType['label'],
                'tabCount' => count($tabLabels) ?: ($linkType['tabCount'] ?? 0),
                'tabLabels' => $tabLabels,
                'showHeaderNewWindow' => $this->newWindow && !$newWindowInLayout,
                'html' => $this->_parseBlockPlaceholder($linkType['html'] ?? '', '__LINK_ID__', $placeholderKey),
                'js' => $this->_namespaceDeferredFieldPayload(
                    $this->_parseBlockPlaceholder($linkType['js'] ?? '', '__LINK_ID__', $placeholderKey),
                ),
            ];
        }

        // Bulk Add is only offered on multi-link fields once explicitly enabled. The
        // per-type `bulk` descriptor below still tells JS *how* a type collects values.
        $bulkAddEnabled = $this->multipleLinks && $this->enableBulkAdd;

        // Enrich the JS link-type config with clipboard paste + bulk-add metadata.
        $bulkLinkTypes = [];

        // At least one enabled type opted into bulk creation — gates the Bulk Add UI.
        $hasBulkAddType = false;

        foreach ($this->getLinkTypes() as $linkType) {
            if (!$linkType->enabled) {
                continue;
            }

            $meta = [
                'handle' => $linkType->handle,
                'label' => Craft::t('hyper', $linkType->label),
                'tabCount' => $linkType->getTabCount(),
                // FQCN for clipboard paste type matching.
                'type' => get_class($linkType),
            ];

            // Type-level opt-in decides whether the type appears in the Bulk Add flow.
            // The JS chooser reads `bulk.mode` to launch the element modal or textarea.
            if ($bulkAddEnabled && $linkType::supportsBulkCreation()) {
                // JS only needs `mode` to decide the dialog body: a one-per-line textarea (text)
                // or a server-rendered native element select (elements). Element types render the
                // real Craft picker via getBulkElementSelectHtml() so source/criteria/condition
                // live server-side and match the per-link picker exactl.
                $meta['bulk'] = [
                    'mode' => $linkType::bulkCreationMode(),
                ];

                $hasBulkAddType = true;
            }

            $bulkLinkTypes[] = $meta;
        }

        // Expose the resolved gate to both the Twig chrome and the JS config.
        $settings['enableBulkAdd'] = $bulkAddEnabled && $hasBulkAddType;

        $inputSettings = $settings;
        $inputSettings['linkTypes'] = $bulkLinkTypes;
        unset($inputSettings['js']);

        Plugin::registerFieldAssets();

        return $view->renderTemplate('hyper/field/input', [
            'id' => $id,
            'name' => $this->handle,
            'field' => $this,
            'element' => $element,
            'isDebug' => Plugin::isDebug(),
            'settings' => $settings,
            'links' => $linksForTwig,
            'linkTypeTemplates' => $linkTypeTemplates,
            'storeValueJson' => Json::encode($storeValue),
            'inputConfig' => [
                'initialValue' => $initialValue,
                'settings' => $inputSettings,
            ],
        ]);
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        $keywords = parent::searchKeywords($value, $element);

        if ($value instanceof LinkCollection) {
            $values = $value->serializeValues();
            unset($values['type'], $values['handle'], $values['newWindow']);

            $keywords = trim(self::_recursiveImplode($values, ' '));
        }

        return $keywords;
    }


    // Private Methods
    // =========================================================================

    private function _getLinkTypeInfoForInput(?ElementInterface $element, string $placeholderKey): array
    {
        $linkTypeInfo = [];

        $view = Craft::$app->getView();
        $oldNamespace = $view->getNamespace();

        // Disable deltas while we render our fields
        $isDeltaRegistrationActive = $view->getIsDeltaRegistrationActive();
        $view->setIsDeltaRegistrationActive(false);

        $ownerSiteId = $element?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        foreach ($this->getLinkTypes() as $linkType) {
            if (!$linkType->enabled) {
                continue;
            }

            $linkType = clone $linkType;
            $linkType->field = $this;

            $view->startJsBuffer();
            $view->startScriptBuffer();

            // Create a fake link ID so that some fields like Matrix will work with this fake element
            $linkType->id = rand();
            // Relation fields on the layout resolve site from the Link element.
            $linkType->siteId = $ownerSiteId;

            // Disregard the namespace of parent fields, or even using `fields`. This keeps our field data separate to Craft
            $view->setNamespace('hyperData[__HYPER_BLOCK_' . $placeholderKey . '__]');

            // Render the fields' HTML and JS to be injected in Vue, along with the config for a new link
            $linkTypeSettings = [
                'type' => get_class($linkType),
                'label' => Craft::t('hyper', $linkType->label),
                'handle' => $linkType->handle,
                'tabCount' => $linkType->getTabCount(),
                'html' => $this->_getBlockHtml($view, $linkType),
            ];

            $js = $view->clearJsBuffer(false);
            $scripts = $view->clearScriptBuffer();
            $linkTypeSettings['js'] = $this->_composeDeferredJs($js, $scripts, $placeholderKey);

            $linkTypeInfo['linkTypes'][] = $linkTypeSettings;
        }

        $view->setNamespace($oldNamespace);
        $view->setIsDeltaRegistrationActive($isDeltaRegistrationActive);

        return $linkTypeInfo;
    }

    public function getBulkLinkBlocks(string $handle, array $seeds, ?ElementInterface $element = null): array
    {
        $prototype = $this->getLinkTypeByHandle($handle);

        // Respect the type-level opt-in — never render a type that didn't ask for bulk creation.
        if (!$prototype || !$prototype->enabled || !$prototype::supportsBulkCreation()) {
            return [];
        }

        $view = Craft::$app->getView();
        $oldNamespace = $view->getNamespace();

        // Deltas off while rendering our own field HTML (matches the other render paths).
        $isDeltaRegistrationActive = $view->getIsDeltaRegistrationActive();
        $view->setIsDeltaRegistrationActive(false);

        // Must reuse the cached placeholder key so the client's id-swap contract lines up.
        $placeholderKey = Hyper::$plugin->getCache()->getOrSet($this->_getCacheKey('placeholderKey'), function() {
            return StringHelper::randomString(10);
        });

        $ownerSiteId = $element?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $tabLabels = $prototype->getTabLabels() ?? [];
        $newWindowInLayout = (bool)($prototype->getFieldLayout()?->isFieldIncluded('newWindow'));
        $showHeaderNewWindow = $this->newWindow && !$newWindowInLayout;

        $blocks = [];

        foreach ($seeds as $seed) {
            $instance = new LinkInstance();
            $instance->linkTypeHandle = $handle;
            $instance->newWindow = $this->defaultNewWindow;

            if (isset($seed['linkValue']) && $seed['linkValue'] !== '') {
                $instance->linkValue = $seed['linkValue'];
            }

            if (!empty($seed['linkSiteId'])) {
                $instance->linkSiteId = (int)$seed['linkSiteId'];
            }

            $link = Hyper::$plugin->getLinks()->createLinkFromInstance($this, $instance);

            if (!$link) {
                continue;
            }

            $link->isNew = true;
            $link->newWindow = $this->defaultNewWindow;
            // Fake id so layout sub-fields (e.g. Matrix) render; site drives relation pickers.
            $link->id = rand();
            $link->siteId = $instance->linkSiteId ?: $ownerSiteId;

            $view->startJsBuffer();
            $view->startScriptBuffer();

            // Keep our field data out of parent namespaces — same key the templates use.
            $view->setNamespace('hyperData[__HYPER_BLOCK_' . $placeholderKey . '__]');

            $html = $this->_getBlockHtml($view, $link);

            $js = $view->clearJsBuffer(false);
            $scripts = $view->clearScriptBuffer();
            $deferredJs = $this->_composeDeferredJs($js, $scripts, $placeholderKey);

            $blocks[] = [
                'handle' => $handle,
                'typeHandle' => $prototype->handle,
                'label' => Craft::t('hyper', (string)$prototype->label),
                'tabCount' => count($tabLabels) ?: $prototype->getTabCount(),
                'tabLabels' => $tabLabels,
                'showHeaderNewWindow' => $showHeaderNewWindow,
                'newWindow' => (bool)$this->defaultNewWindow,
                'html' => $this->_parseBlockPlaceholder($html, '__LINK_ID__', $placeholderKey),
                'js' => $this->_namespaceDeferredFieldPayload(
                    $this->_parseBlockPlaceholder($deferredJs, '__LINK_ID__', $placeholderKey),
                ),
                'serialized' => $link->getSerializedValues(),
            ];
        }

        $view->setNamespace($oldNamespace);
        $view->setIsDeltaRegistrationActive($isDeltaRegistrationActive);

        return $blocks;
    }

    public function getBulkElementSelectHtml(string $handle, ?int $limit = null): string
    {
        $linkType = $this->getLinkTypeByHandle($handle);

        if (!($linkType instanceof ElementLink) || !$linkType->enabled || !$linkType::supportsBulkCreation()) {
            return '';
        }

        // Same URI/status constraint the per-link picker applies.
        $criteria = ['status' => null];

        if (!$linkType->allowElementsWithoutUri && $linkType::supportsUriSelectorCriteria()) {
            $criteria['uri'] = ':notempty:';
        }

        return Cp::elementSelectHtml([
            // Unique id so re-opening / switching types never collides with a prior mount.
            'id' => 'hyper-bulk-' . StringHelper::randomString(10),
            'name' => 'bulkElements',
            'elementType' => $linkType::elementType(),
            'limit' => $limit,
            'sources' => $linkType->getAvailableSources(),
            'showSiteMenu' => $linkType->showSiteMenu,
            'storageKey' => 'hyper.bulk.' . $this->handle . '.' . $handle,
            'selectionLabel' => $linkType->selectionLabel ?: $linkType::defaultSelectionLabel(),
            'criteria' => $criteria,
            'condition' => $linkType->getSelectionCondition(),
        ]);
    }

    private function _getLinksForInput(LinkCollection $links, string $placeholderKey, ?ElementInterface $element = null): array
    {
        $preppedValues = [];

        // Do not auto-seed a blank single-link row — empty fields show an Add CTA.
        // Still honour minLinks for multi-link fields.
        if ($this->multipleLinks && $this->minLinks) {
            // Seed min links server-side so client init does not mutate the hidden store JSON.
            $linkList = $links->getLinks();
            $toCreate = $this->minLinks - count($linkList);

            for ($i = 0; $i < $toCreate; $i++) {
                $link = Hyper::$plugin->getLinks()->createDefaultContentLink($this, $this->defaultLinkType);

                if ($link) {
                    $linkList[] = $link;
                }
            }

            if ($toCreate > 0) {
                $links->setLinks($linkList);
            }
        }

        $view = Craft::$app->getView();
        $oldNamespace = $view->getNamespace();

        // Disable deltas while we render our fields
        $isDeltaRegistrationActive = $view->getIsDeltaRegistrationActive();
        $view->setIsDeltaRegistrationActive(false);

        $ownerSiteId = $element?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        // For each Link element, render the fields and convert to an array
        foreach ($links as $key => $link) {
            $view->startJsBuffer();
            $view->startScriptBuffer();

            // Create a fake link ID so that some fields like Matrix will work with this fake element
            $link->id = rand();
            $link->siteId = $ownerSiteId;

            // Disregard the namespace of parent fields, or even using `fields`. This keeps our field data separate to Craft
            $view->setNamespace('hyperData[__HYPER_BLOCK_' . $placeholderKey . '__]');

            $preppedValues[$key] = $link->getInputConfig();
            $preppedValues[$key]['id'] = $link->id;
            $preppedValues[$key]['serialized'] = $link->getSerializedValues();
            $preppedValues[$key]['html'][$link->handle] = $this->_getBlockHtml($view, $link);

            $js = $view->clearJsBuffer(false);
            $scripts = $view->clearScriptBuffer();
            $preppedValues[$key]['js'][$link->handle] = $this->_composeDeferredJs($js, $scripts, $placeholderKey);
        }

        $view->setNamespace($oldNamespace);
        $view->setIsDeltaRegistrationActive($isDeltaRegistrationActive);

        return $preppedValues;
    }

    private function _parseBlockPlaceholder(string $html, string $linkId, string $placeholderKey): string
    {
        if ($html === '') {
            return '';
        }

        return str_replace('__HYPER_BLOCK_' . $placeholderKey . '__', $linkId, $html);
    }

    private function _namespaceDeferredFieldPayload(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $namespace = 'fields';
        $normalized = Html::id($namespace);

        $payload = Html::namespaceAttributes($payload, $namespace);
        $payload = Html::namespaceInputs($payload, $namespace);

        // Craft CP input constructors pass element ids in JSON (e.g. EntrySelectInput).
        $payload = preg_replace(
            '/"id"\s*:\s*"(hyperData-[^"]+)"/',
            '"id":"' . $normalized . '-$1"',
            $payload,
        ) ?? $payload;

        // jQuery/DOM selectors emitted by {% js %} blocks.
        $payload = preg_replace(
            '/#([\'"])(hyperData-[^\'"]+)\1/',
            '#$1' . $normalized . '-$2$1',
            $payload,
        ) ?? $payload;

        // Input names in JSON settings objects.
        $payload = preg_replace(
            '/"name"\s*:\s*"hyperData(\[[^\]]+\](?:\[[^\]]+\])*)"/',
            '"name":"' . $namespace . '[hyperData]$1"',
            $payload,
        ) ?? $payload;

        return $payload;
    }

    private function _getBlockHtml(View $view, LinkInterface $link): string
    {
        if ($link instanceof linkTypes\MissingLink) {
            $error = Craft::t('hyper', 'Link type class \'{type}\' is invalid.', [
                'type' => $link->expectedType,
            ]);

            return Html::tag('div', $error, ['class' => 'error']);
        }

        try {
            $linkFieldLayout = $link->getFieldLayout();

            if (!$linkFieldLayout) {
                return Html::tag('div', Craft::t('hyper', 'Unable to render field. Please resave the field settings.'), ['class' => 'error']);
            }

            // Clone so we can stamp LinkField without mutating the cached layout.
            $fieldLayout = clone($linkFieldLayout);

            // Add the link type to the LinkField field layout element, so we generate the correct HTML for the type
            if ($fieldLayout->isFieldIncluded('linkValue')) {
                $linkValueField = $fieldLayout->getField('linkValue');
                $linkValueField->field = $this;
                $linkValueField->link = $link;
            }

            // Full Craft form — all layout tabs as sibling panes (Vizy/Neo/Matrix style).
            // Inactive panes start with `.hidden`; header tabs toggle visibility in the CP.
            $form = $fieldLayout->createForm($link);

            // Note: we can't just wrap FieldLayoutForm::render() in a callable passed to namespaceInputs() here,
            // because the form HTML is for JavaScript; not returned by inputHtml().
            return $view->namespaceInputs($form->render());
        } catch (Throwable $e) {
            $error = Craft::t('hyper', 'Unable to render field - {message} {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            Hyper::error($error);

            return Html::tag('div', $error, ['class' => 'error']);
        }
    }

    public function getLinkTypeSettingsForHtml(): array
    {
        return $this->_getLinkTypeSettings();
    }

    private function _getLinkTypeSettings(): array
    {
        $linkTypes = [];

        $linksService = Hyper::$plugin->getLinks();

        // Shared fields render a hidden custom editor seeded from their selected config.
        if (!$this->hasCustomLinkTypes()) {
            $shell = new self([
                'linkTypeConfig' => LinkTypeConfigs::CUSTOM_HANDLE,
                'linkTypes' => Hyper::$plugin->getLinkTypeConfigs()->createDetachedCopy($this->linkTypeConfig),
            ]);

            return $shell->_getLinkTypeSettings();
        }

        // For any already-saved link type settings, prep them
        foreach ($this->getLinkTypes() as $linkType) {
            $linkTypes[] = $this->_getLinkTypeSettingsConfig($linkType);
        }

        $registeredLinkTypes = $linksService->getAllLinkTypes();

        // Sort alphabetically by label
        sort($registeredLinkTypes);

        // Then, ensure that we always have at least one instance of a registered link type
        foreach ($registeredLinkTypes as $linkTypeClass) {
            $hasLinkType = ArrayHelper::firstWhere($linkTypes, 'type', $linkTypeClass);

            if ($hasLinkType) {
                continue;
            }

            $linkType = Hyper::$plugin->getLinks()->createSettingsPrototype($linkTypeClass);
            $linkTypes[] = $this->_getLinkTypeSettingsConfig($linkType);
        }

        foreach ($linkTypes as $key => $linkType) {
            // Encode the `layoutConfig` as we require it to be a JSON-string in Vue templates
            if (is_array($linkType['layoutConfig'])) {
                $linkTypes[$key]['layoutConfig'] = Json::encode($linkType['layoutConfig']);
            }
        }

        return $linkTypes;
    }

    private function _composeDeferredJs(string|false $js, array|false $scripts, string $placeholderKey): string
    {
        $payload = '';

        if (is_string($js) && $js !== '') {
            // Keep registerJs() output deferred with a stable script ID.
            $payload .= '<script id="hyper-__HYPER_BLOCK_' . $placeholderKey . '__-script">' . $js . '</script>';
        }

        if (is_array($scripts)) {
            // registerScriptWithVars() output (e.g. CKEditor module scripts) is already full <script> tags.
            $scriptHtml = $this->_flattenScriptBuffer($scripts);

            if ($scriptHtml !== '') {
                $payload .= $scriptHtml;
            }
        }

        return $payload;
    }

    private function _flattenScriptBuffer(array $scripts): string
    {
        $output = '';
        $positions = [View::POS_HEAD, View::POS_BEGIN, View::POS_END];

        foreach ($positions as $position) {
            if (!empty($scripts[$position]) && is_array($scripts[$position])) {
                $output .= implode("\n", $scripts[$position]) . "\n";
                unset($scripts[$position]);
            }
        }

        // Include any unexpected positions to avoid dropping registered scripts.
        foreach ($scripts as $positionScripts) {
            if (is_array($positionScripts) && $positionScripts) {
                $output .= implode("\n", $positionScripts) . "\n";
            }
        }

        return $output;
    }

    private function _getLinkTypeSettingsConfig(LinkInterface $linkType): array
    {
        $view = Craft::$app->getView();
        $linkTypeClass = get_class($linkType);

        // Setup defaults. Built-in single instances default to the short, author-owned
        // type key (e.g. `url`) so handles and GraphQL names stay clean and guessable.
        $linkType->label = $linkType->label ?? $linkType::displayName();
        $linkType->handle = $linkType->handle ?? $linkTypeClass::typeKey();

        // Twig already namespaces inputs via the field settings wrapper and `linkTypes[…]` block.
        // Running namespaceInputs() here would double-prefix names (types[…][types][…]) and break saves.
        $view->startJsBuffer();
        $html = $view->renderTemplate('hyper/field/_link-type-settings', [
            'field' => $this,
            'linkType' => $linkType,
            // `isCustom` is now a real stored flag — no longer inferred from the handle string.
            'isCustom' => $linkType->isCustom,
        ]);
        $html = str_replace('__LINK_TYPE__', $linkType->handle, $html);
        $js = $view->clearJsBuffer();

        // Render the template again, but with no field context for the template for new links
        $newLink = new $linkTypeClass;
        $newLink->label = 'New ' . $linkType::displayName();
        $newLink->isNew = true;

        $view->startJsBuffer();
        $htmlTemplate = $view->renderTemplate('hyper/field/_link-type-settings', [
            'field' => new HyperField(),
            'linkType' => $newLink,
            'isCustom' => true,
        ]);
        $jsTemplate = $view->clearJsBuffer();

        return array_merge($linkType->getSettingsConfig(), [
            'displayName' => $linkType::displayName(),
            'hasErrors' => $linkType->hasErrors(),
            'handle' => $linkType->handle,
            'html' => $html,
            'js' => $js,
            'htmlTemplate' => $htmlTemplate,
            'jsTemplate' => $jsTemplate,
        ]);
    }

    private function _normalizeLayoutConfig(array $config = []): array
    {
        // This is supremely stupid. When settings for the field layout come through when editing the field
        // they'll contain extra info. Project Config, for some bizarre reason, strips this out - which is fine - 
        // but doesn't re-index the array. So we end up with inconsistent `__assoc__` content in project config!
        // The way to get around this is to pass it all through the PC helpers before setting on the link.
        $layoutConfig = $config['layoutConfig'] ?? [];

        if (is_string($layoutConfig)) {
            $layoutConfig = Json::decode($layoutConfig);
        }

        // Ensure we remove `uid` from the `layoutConfig` - we don't want it
        ArrayHelper::remove($layoutConfig, 'uid');

        if (!is_array($layoutConfig)) {
            return [];
        }

        $firstTab = $layoutConfig['tabs'][0] ?? [];

        // We only need to run this when the field layout config is not already transformed. As this is called each `setLinkTypes()`
        // it'll be run even when reading from the database or project config, where it's already "correct". We're checking on `userCondition`
        // purely because that's a value we know is stripped out by project config's saving mechanism for a field layout.
        if (array_key_exists('userCondition', $firstTab)) {
            $newLayout = FieldLayout::createFromConfig($layoutConfig);
            $fieldLayoutConfig = $newLayout->getConfig();

            $fieldLayoutConfig = ProjectConfig::packAssociativeArrays($fieldLayoutConfig);
            $fieldLayoutConfig = ProjectConfig::cleanupConfig($fieldLayoutConfig);

            return ProjectConfig::unpackAssociativeArrays($fieldLayoutConfig);
        }

        // Fix potential Craft 5.8+ issue
        if (isset($layoutConfig['cardThumbAlignment']) && is_array($layoutConfig['cardThumbAlignment'])) {
            $layoutConfig['cardThumbAlignment'] = reset($layoutConfig['cardThumbAlignment']);
        }

        return $layoutConfig;
    }

    private static function _preserveScalarLinkValues(array $values): array
    {
        foreach ($values as $key => $linkValues) {
            if (!is_array($linkValues)) {
                continue;
            }

            $type = $linkValues['type'] ?? null;

            if (!in_array($type, [linkTypes\Phone::class, linkTypes\Email::class], true)) {
                continue;
            }

            if (!array_key_exists('linkValue', $linkValues)) {
                continue;
            }

            $linkValue = $linkValues['linkValue'];

            if ($linkValue !== null && $linkValue !== '' && is_scalar($linkValue)) {
                $values[$key]['linkValue'] = (string)$linkValue;
            }
        }

        return $values;
    }

    private static function _recursiveImplode(array $array, string $glue = ',', bool $include_keys = false, bool $trim_all = false): string
    {
        $glued_string = '';

        // Recursively iterates array and adds key/value to glued string
        array_walk_recursive($array, function($value, $key) use ($glue, $include_keys, &$glued_string) {
            $include_keys && $glued_string .= $key . $glue;
            $glued_string .= $value . $glue;
        });

        // Removes last $glue from string
        $glue !== '' && $glued_string = substr($glued_string, 0, -strlen($glue));

        // Trim ALL whitespace
        $trim_all && $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);

        return (string)$glued_string;
    }

    private function _getCacheKey(string $key): string
    {
        return $this->id . '-' . $this->handle . '-' . $key;
    }

    private function _renderLink(mixed $value): string
    {
        if (!($value instanceof LinkCollection)) {
            return '';
        }

        if (!$value->getUrl()) {
            return '';
        }

        return Html::tag('a', $value->getText(), [
            'href' => $value->getUrl(),
            'rel' => 'noopener',
            'target' => '_blank',
            'class' => 'go',
            'title' => Craft::t('app', 'Visit webpage'),
        ]);
    }
}
