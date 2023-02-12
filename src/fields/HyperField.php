<?php
namespace verbb\hyper\fields;

use verbb\hyper\Hyper;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\gql\types\generators\LinkTypeGenerator;
use verbb\hyper\links as linkTypes;
use verbb\hyper\helpers\Plugin;
use verbb\hyper\models\LinkCollection;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\fields\conditions\EmptyFieldConditionRule;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\validators\ArrayValidator;
use craft\web\View;

use yii\db\Schema;

use GraphQL\Type\Definition\Type;
use LitEmoji\LitEmoji;

class HyperField extends Field
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Hyper');
    }

    public static function valueType(): string
    {
        return 'string|null';
    }


    // Properties
    // =========================================================================

    public ?string $defaultLinkType = 'default-verbb-hyper-links-url';
    public bool $newWindow = true;
    public bool $defaultNewWindow = false;
    public bool $multipleLinks = false;
    public ?int $minLinks = null;
    public ?int $maxLinks = null;
    public array $linkTypes = [];
    public ?int $fieldLayoutId = null;
    public string $columnType = Schema::TYPE_TEXT;

    private bool $_isStatic = false;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        // Convert link types as arrays to Link objects.
        // This can be fired before the Hyper plugin is ready in some instances
        if (array_key_exists('linkTypes', $config)) {
            if (is_array($config['linkTypes'])) {
                foreach ($config['linkTypes'] as $key => $linkType) {
                    if (is_array($linkType)) {
                        $config['linkTypes'][$key] = Hyper::$plugin->getLinks()->createLink($linkType);
                    }
                }
            }
        }

        parent::__construct($config);
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['minLinks', 'maxLinks'], 'integer', 'min' => 0];
        $rules[] = ['linkTypes', 'validateLinkTypes'];

        return $rules;
    }

    public function getContentColumnType(): array|string
    {
        return $this->columnType;
    }

    public function validateLinkTypes(): void
    {
        // Ensure there is at least one enabled link type
        if (!ArrayHelper::getColumn($this->linkTypes, 'enabled')) {
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

        $idPrefix = StringHelper::randomString(10);

        // Create the Hyper Settings Vue component
        $js = 'new Craft.Hyper.Settings("' . $idPrefix . '");';
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
            'idPrefix' => $idPrefix,
            'field' => $this,
            'linkTypes' => $linkTypes,
            'registeredLinkTypes' => $registeredLinkTypes,

            // Required placeholder to work with nested namespace (Matrix)
            'namespacedName' => $view->namespaceInputName('__PREFIX__'),
            'namespacedId' => $view->namespaceInputId('__PREFIX__'),
        ]);
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $id = Html::id($this->handle);

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
        $linkTypeInfo = $this->_getLinkTypeInfoForInput($element);
        $settings['linkTypes'] = $linkTypeInfo['linkTypes'] ?? [];
        $settings['js'] = $linkTypeInfo['js'] ?? [];

        // Prepare the link element values for the field, including pre-rendered HTML
        $value = $this->_getLinksForInput($value);

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

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): LinkCollection
    {
        if ($value instanceof LinkCollection) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            // Support emoji's for anything
            $value = LitEmoji::shortcodeToUnicode($value);
            
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value)) {
            $value = [];
        }

        return new LinkCollection($this, $value, $element);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof LinkCollection) {
            $value = $value->serializeValues($element);

            // Support emoji's for anything
            return Json::decode(LitEmoji::unicodeToShortcode(Json::encode($value)));
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
        $linkTypes = [];

        foreach ($this->linkTypes as $linkType) {
            if (!$linkType->validate()) {
                $hasErrors = true;
            }

            // Set up the field layout config - it'll be saved later
            if (!$linkType->layoutConfig) {
                $linkType->layoutConfig = $linkType::getDefaultFieldLayout()->getConfig();
            }

            // Generate a layout UID if not already set
            if (!$linkType->layoutUid) {
                $linkType->layoutUid = StringHelper::UUID();
            }

            $linkTypes[] = $linkType->getSettingsConfig();
        }

        if ($hasErrors) {
            $this->addError('linkTypes', Craft::t('hyper', 'Correct the above errors.'));

            return false;
        }

        $this->linkTypes = $linkTypes;

        // Any fields not in the global scope won't trigger a PC change event. Go manual.
        if ($this->context !== 'global') {
            Hyper::$plugin->getService()->saveField($this->linkTypes);
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
            $link = $this->linkTypes[0] ?? null;
        } else {
            $link = ArrayHelper::firstWhere($this->linkTypes, 'handle', $handle);
        }

        if ($link) {
            $link->field = $this;
        }

        return $link;
    }

    public function getContentGqlType(): Type|array
    {
        $type = LinkTypeGenerator::generateType($this);

        return Type::listOf($type);
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
        $links = $element->getFieldValue($this->handle);

        $scenario = $element->getScenario();

        foreach ($links as $i => $link) {
            $link->setScenario($scenario);

            // Set a flag whether the Hyper field itself is required
            $link->isFieldRequired = $this->required;

            if (!$link->validate()) {
                $element->addModelErrors($link, "{$this->handle}[{$i}]");
            }
        }

        if ($element->getScenario() === Element::SCENARIO_LIVE && ($this->minLinks || $this->maxLinks)) {
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


    // Private Methods
    // =========================================================================

    private function _getLinkTypeInfoForInput(?ElementInterface $element): array
    {
        $linkTypeInfo = [];

        $view = Craft::$app->getView();
        $oldNamespace = $view->getNamespace();
        $view->setNamespace($view->namespaceInputName("$this->handle[__HYPER_BLOCK__]"));

        foreach ($this->linkTypes as $linkType) {
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

    private function _getLinksForInput(LinkCollection $links): array
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
        $view->setNamespace($view->namespaceInputName("$this->handle[__HYPER_BLOCK__]"));

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
        // Render just the first tab
        $fieldLayout = clone($link->getFieldLayout());

        if (!$fieldLayout) {
            return '';
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
    }

    private function _getLinkTypeSettings(): array
    {
        $linkTypes = [];

        $linksService = Hyper::$plugin->getLinks();

        // For any already-saved link type settings, prep them
        foreach ($this->linkTypes as $linkType) {
            $linkTypes[get_class($linkType)] = $this->_getLinkTypeSettingsConfig($linkType);
        }

        // Then, ensure that we always have at least one instance of a registered link type
        foreach ($linksService->getAllLinkTypes() as $linkTypeClass) {
            if (isset($linkTypes[$linkTypeClass])) {
                continue;
            }

            $linkType = $linksService->createLink($linkTypeClass);
            $linkTypes[$linkTypeClass] = $this->_getLinkTypeSettingsConfig($linkType);
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

        // Sort alphabetically by label
        usort($linkTypes, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        return array_values($linkTypes);
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
}
