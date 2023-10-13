<?php
namespace verbb\hyper\fields;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\gql\interfaces\LinkInterface as GqlLinkInterface;
use verbb\hyper\links as linkTypes;
use verbb\hyper\helpers\Plugin;
use verbb\hyper\helpers\StringHelper;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\services\Links;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\db\ElementQueryInterface;
use craft\fields\conditions\EmptyFieldConditionRule;
use craft\helpers\ArrayHelper;
use craft\helpers\Gql;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\ProjectConfig;
use craft\models\FieldLayout;
use craft\validators\ArrayValidator;
use craft\web\View;

use yii\db\Schema;

use Throwable;

use GraphQL\Type\Definition\Type;

class HyperField extends Field
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Hyper');
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', LinkCollection::class);
    }


    // Properties
    // =========================================================================

    public ?string $defaultLinkType = 'default-verbb-hyper-links-url';
    public bool $newWindow = true;
    public bool $defaultNewWindow = false;
    public bool $multipleLinks = false;
    public ?int $minLinks = null;
    public ?int $maxLinks = null;
    public ?int $fieldLayoutId = null;
    public array $migrationData = [];

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

        // Remove unused settings
        unset($config['columnType']);

        parent::__construct($config);
    }

    public function getSettings(): array
    {
        $settings = parent::getSettings();

        // Serialize the link types as arrays instead of arrays of Link classes
        $settings['linkTypes'] = array_map(function($linkType) {
            return $linkType->getSettingsConfig();
        }, $this->getLinkTypes());

        return $settings;
    }

    public function validateLinkTypes(): void
    {
        // Ensure there is at least one enabled link type
        if (!ArrayHelper::getColumn($this->getLinkTypes(), 'enabled')) {
            $this->addError('linkTypes', Craft::t('hyper', 'You must enable at least one link type.'));
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

        // Create the Hyper Settings Vue component
        $js = 'new Craft.Hyper.Settings(' .
            Json::encode($inputNamePrefix, JSON_UNESCAPED_UNICODE) . 
        ');';
        
        $this->_registerJs($view, $js);

        // Get the link type settings (set defaults or normalize existing saved settings)
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
            'linkTypes' => $linkTypes,
            'registeredLinkTypes' => $registeredLinkTypes,

            // Required placeholder to work with nested namespace (Matrix)
            'namespacedName' => $view->namespaceInputName('__PREFIX__'),
            'namespacedId' => $view->namespaceInputId('__PREFIX__'),
        ]);
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

        $settings = [
            'fieldId' => $this->id,
            'handle' => $this->handle,
            'defaultLinkType' => $this->defaultLinkType,
            'defaultNewWindow' => $this->defaultNewWindow,
            'newWindow' => $this->newWindow,
            'multipleLinks' => $this->multipleLinks,
            'minLinks' => $this->minLinks,
            'maxLinks' => $this->maxLinks,
            'namespacedName' => $view->namespaceInputName($this->handle),
            'namespacedId' => $view->namespaceInputId($this->handle),
            'isStatic' => $this->_isStatic,
        ];

        // Prepare the link types and HTML for fields
        $placeholderKey = StringHelper::randomString(10);
        $linkTypeInfo = $this->_getLinkTypeInfoForInput($element, $placeholderKey);
        $settings['linkTypes'] = $linkTypeInfo['linkTypes'] ?? [];
        $settings['js'] = $linkTypeInfo['js'] ?? [];
        $settings['placeholderKey'] = $placeholderKey;

        // Prepare the link element values for the field, including pre-rendered HTML
        $value = $this->_getLinksForInput($value, $placeholderKey);

        // Create the Hyper Input Vue component
        $js = 'new Craft.Hyper.Input("' . $view->namespaceInputId($id) . '");';
        $this->_registerJs($view, $js);

        return $view->renderTemplate('hyper/field/input', [
            'id' => $id,
            'name' => $this->handle,
            'field' => $this,
            'element' => $element,
            'value' => $value,
            'settings' => $settings,
        ]);
    }

    public function normalizeValue(mixed $value, ElementInterface $element = null): mixed
    {
        if ($value instanceof LinkCollection) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            $value = Json::decodeIfJson($value);

            if (is_array($value)) {
                $value = self::_decodeStringValues($value);
            }
        }

        if (!is_array($value)) {
            $value = [];
        }

        return new LinkCollection($this, $value, $element);
    }

    public function serializeValue(mixed $value, ElementInterface $element = null): mixed
    {
        if ($value instanceof LinkCollection) {
            $value = $value->serializeValues($element);

            return Json::decode(Json::encode(self::_encodeStringValues($value)));
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

        // Any fields not in the global scope won't trigger a PC change event. Go manual.
        if ($this->context !== 'global') {
            Hyper::$plugin->getService()->saveField($this->getLinkTypes());
        }

        return true;
    }

    public function afterSave(bool $isNew): void
    {
        Hyper::$plugin->getFieldCache()->setCache($this);

        parent::afterSave($isNew);
    }

    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        Hyper::$plugin->getElementCache()->upsertCache($this, $element);

        parent::afterElementSave($element, $isNew);
    }

    public function getLinkTypeByHandle(?string $handle): ?LinkInterface
    {
        if (!$handle) {
            $link = $this->getLinkTypes()[0] ?? null;
        } else {
            $link = ArrayHelper::firstWhere($this->getLinkTypes(), 'handle', $handle);
        }

        if ($link) {
            $link->field = $this;
        }

        return $link;
    }

    public function modifyElementsQuery(ElementQueryInterface $query, mixed $value): void
    {
        // If we're trying to eager-load this field, remove it as it won't work correctly and return an empty value
        if ($query->with && is_array($query->with)) {
            if (($key = array_search($this->handle, $query->with)) !== false) {
                unset($query->with[$key]);
            }
        }
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

        if ($this->minLinks || $this->maxLinks) {
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

    public function getLinkTypes(): array
    {
        if ($this->_linkTypes) {
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
            } else {
                // Some extra handling here when setting from the POST.
                $config['layoutConfig'] = $this->_normalizeLayoutConfig($config);

                $linkType = Links::createLink($config);
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

    public function setLinkTypes(array $linkTypes): void
    {
        // Set the raw, serialized link types, which are created as objects later. Doing that too early
        // leads to a whole ream of issues, so do the work in the getter.
        $this->_serializedLinkTypes = $linkTypes;
    }

    /**
     * Returns all the link types' fields.
     *
     * @param string[]|null $typeHandles The Hyper link type handles to return fields for.
     * If null, all link type fields will be returned.
     * @return FieldInterface[]
     */
    public function getLinkTypeFields(?array $typeHandles = null): array
    {
        if (!isset($this->_linkTypeFields)) {
            $this->_linkTypeFields = [];

            if (!empty($linkTypes = $this->getLinkTypes())) {
                
                $fieldColumnPrefix = 'field_';
                
                foreach ($linkTypes as $linkType) {
                    $fields = $linkType->getCustomFields();
                    foreach ($fields as $field) {
                        $field->columnPrefix = $fieldColumnPrefix;
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


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['minLinks', 'maxLinks'], 'integer', 'min' => 0];
        $rules[] = ['linkTypes', 'validateLinkTypes'];

        return $rules;
    }


    // Private Methods
    // =========================================================================

    private function _getLinkTypeInfoForInput(?ElementInterface $element, string $placeholderKey): array
    {
        $linkTypeInfo = [];

        $view = Craft::$app->getView();
        $oldNamespace = $view->getNamespace();
        $view->setNamespace($view->namespaceInputName("$this->handle[__HYPER_BLOCK_{$placeholderKey}__]"));

        foreach ($this->getLinkTypes() as $linkType) {
            if (!$linkType->enabled) {
                continue;
            }

            $view->startJsBuffer();

            // Render the fields' HTML and JS to be injected in Vue, along with the config for a new link
            $linkTypeSettings = [
                'type' => get_class($linkType),
                'label' => $linkType->label,
                'handle' => $linkType->handle,
                'tabCount' => $linkType->getTabCount(),
                'html' => $this->_getBlockHtml($view, $linkType),
            ];

            $js = $view->clearJsBuffer(false);
            $linkTypeSettings['js'] = '<script id="hyper-' . $view->namespaceInputId('script') . '">' . $js . '</script>';

            $linkTypeInfo['linkTypes'][] = $linkTypeSettings;
        }

        $view->setNamespace($oldNamespace);

        return $linkTypeInfo;
    }

    private function _getLinksForInput(LinkCollection $links, string $placeholderKey): array
    {
        $preppedValues = [];

        // If a brand-new element with no links, create the defaults. Done here instead of `normalizeValue`
        // or in the collection itself to prevent it being saved to the content table immediately.
        if ($links->isEmpty() && !$this->multipleLinks) {
            // Check to see if there's already a link in the collection, or really blank
            $link = $links->getLinks()[0] ?? $this->getLinkTypeByHandle($this->defaultLinkType);

            if ($link) {
                $link->newWindow = $this->defaultNewWindow;
                $links->setLinks([$link]);
            }
        }

        $view = Craft::$app->getView();
        $oldNamespace = $view->getNamespace();
        $view->setNamespace($view->namespaceInputName("$this->handle[__HYPER_BLOCK_{$placeholderKey}__]"));

        // For each Link element, render the fields and convert to an array
        foreach ($links as $key => $link) {
            $view->startJsBuffer();

            $preppedValues[$key] = $link->getInputConfig();
            $preppedValues[$key]['id'] = StringHelper::randomString(10);
            $preppedValues[$key]['html'][$link->handle] = $this->_getBlockHtml($view, $link);

            $js = $view->clearJsBuffer(false);
            $preppedValues[$key]['js'][$link->handle] = '<script id="hyper-' . $view->namespaceInputId('script') . '">' . $js . '</script>';
        }

        $view->setNamespace($oldNamespace);

        return $preppedValues;
    }

    private function _getBlockHtml(View $view, LinkInterface $link): string
    {
        try {
            // Render just the first tab
            $linkFieldLayout = $link->getFieldLayout();

            if (!$linkFieldLayout) {
                return Html::tag('div', Craft::t('hyper', 'Unable to render field. Please resave the field settings.'), ['class' => 'error']);
            }

            $fieldLayout = clone($linkFieldLayout);

            if (!$fieldLayout) {
                return Html::tag('div', Craft::t('hyper', 'Unable to render field layout. Please resave the field settings.'), ['class' => 'error']);
            }

            $layoutTab = $fieldLayout->getTabs()[0] ?? [];
            $fieldLayout->setTabs([$layoutTab]);

            // Add the link type to the LinkField field layout element, so we generate the correct HTML for the type
            $linkValueField = $fieldLayout->getField('linkValue');
            $linkValueField->field = $this;
            $linkValueField->link = $link;

            $form = $fieldLayout->createForm($link);

            // Note: we can't just wrap FieldLayoutForm::render() in a callable passed to namespaceInputs() here,
            // because the form HTML is for JavaScript; not returned by inputHtml().
            return $view->namespaceInputs($form->render());
        } catch (Throwable $e) {
            return Html::tag('div', Craft::t('hyper', 'Unable to render field - {e}.', ['e' => $e->getMessage()]), ['class' => 'error']);
        }
    }

    private function _getLinkTypeSettings(): array
    {
        $linkTypes = [];

        $linksService = Hyper::$plugin->getLinks();

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

            $linkType = Links::createLink($linkTypeClass);
            $linkTypes[] = $this->_getLinkTypeSettingsConfig($linkType);
        }

        $disabledTypes = [
            linkTypes\Asset::class,
            linkTypes\Custom::class,
            linkTypes\Embed::class,
            linkTypes\Phone::class,
            linkTypes\Site::class,
            linkTypes\User::class,
        ];

        foreach ($linkTypes as $key => $linkType) {
            // Encode the `layoutConfig` as we require it to be a JSON-string in Vue templates
            if (is_array($linkType['layoutConfig'])) {
                $linkTypes[$key]['layoutConfig'] = Json::encode($linkType['layoutConfig']);
            }

            // Setup defaults for brand-new fields
            if (!$this->id && in_array($linkType['type'], $disabledTypes)) {
                $linkTypes[$key]['enabled'] = false;
            }
        }

        return $linkTypes;
    }

    private function _getLinkTypeSettingsConfig(LinkInterface $linkType): array
    {
        $view = Craft::$app->getView();
        $linkTypeClass = get_class($linkType);

        // Setup defaults
        $linkType->label = $linkType->label ?? $linkType::displayName();
        $linkType->handle = $linkType->handle ?? 'default-' . StringHelper::toKebabCase($linkTypeClass);

        $view->startJsBuffer();
        $html = $view->namespaceInputs($view->renderTemplate('hyper/field/_link-type-settings', [
            'field' => $this,
            'linkType' => $linkType,
        ]));
        $js = $view->clearJsBuffer();

        // Render the template again, but with no field context for the template for new links
        $newLink = new $linkTypeClass;
        $newLink->label = 'New ' . $linkType::displayName();

        $view->startJsBuffer();
        $htmlTemplate = $view->namespaceInputs($view->renderTemplate('hyper/field/_link-type-settings', [
            'field' => new HyperField(),
            'linkType' => $newLink,
            'isCustom' => true,
        ]));
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

    private function _registerJs(View $view, string $js): void
    {
        Plugin::registerAsset('field/src/js/hyper.js');

        // Wait for Hyper JS to be loaded, either through an event listener, or by a flag.
        // This covers if this script is run before, or after the Hyper JS has loaded
        $view->registerJs('document.addEventListener("vite-script-loaded", function(e) {' .
            'if (e.detail.path === "field/src/js/hyper.js") {' . $js . '}' .
        '}); if (Craft.HyperReady) {' . $js . '}');
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

        return $layoutConfig;
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

    private static function _decodeStringValues(array $values)
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = self::_decodeStringValues($value);
            } else if (is_string($value)) {
                $value = StringHelper::shortcodesToEmoji($value);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private static function _encodeStringValues(array $values)
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = self::_encodeStringValues($value);
            } else if (is_string($value)) {
                $value = StringHelper::emojiToShortcodes($value);
            }

            $values[$key] = $value;
        }

        return $values;
    }
}
