<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\elements\conditions\ElementLinkCondition;
use verbb\hyper\fields\HyperField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\models\LinkInstance;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\conditions\ConditionInterface;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Cp;
use craft\helpers\StringHelper;

abstract class ElementLink extends Link implements ElementLinkInterface
{
    // Static Methods
    // =========================================================================

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('app', 'Choose');
    }

    public static function supportsBulkCreation(): bool
    {
        return true;
    }

    public static function bulkCreationMode(): ?string
    {
        return 'elements';
    }

    public static function supportsUriSelectorCriteria(): bool
    {
        return true;
    }

    public static function supportsSourceUriFiltering(): bool
    {
        return false;
    }

    public static function limitSourcesLabel(): string
    {
        return Craft::t('hyper', 'Limit Sources with URIs');
    }

    public static function createCondition(): ElementConditionInterface
    {
        return Craft::createObject(ElementLinkCondition::class, [
            static::class,
            ['targetElementType' => static::elementType()],
        ]);
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
    public bool $limitSourcesToSectionsWithUri = false;

    private ?ElementInterface $_element = null;
    private array|null|ElementConditionInterface $_selectionCondition = null;


    // Public Methods
    // =========================================================================

    public function __construct($config = [])
    {
        // These were previously template-only variables, not saved link type settings.
        unset($config['applyUriCriteria'], $config['showAllowElementsWithoutUri']);

        parent::__construct($config);
    }

    public function count(): int|bool
    {
        return $this->isEmpty() ? false : true;
    }

    public function toInstance(): LinkInstance
    {
        $instance = parent::toInstance();
        $instance->linkSiteId = $this->linkSiteId;

        return $instance;
    }

    public function clearContentState(): void
    {
        parent::clearContentState();

        $this->linkSiteId = null;
        $this->_element = null;
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

        $linkValue = array_values(array_filter($linkValue, static fn(mixed $value): bool => $value !== null && $value !== ''));
        $values['linkValue'] = $linkValue === [] ? null : $linkValue;

        $previousTargetId = $this->_getLinkTargetId();

        parent::setAttributes($values, $safeOnly);

        if ($this->_getLinkTargetId() !== $previousTargetId) {
            $this->_element = null;
        }
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
        $values['limitSourcesToSectionsWithUri'] = $this->limitSourcesToSectionsWithUri;

        // Persist only when rules exist — empty builders stay out of project config.
        if ($selectionCondition = $this->getSelectionCondition()) {
            $values['selectionCondition'] = $selectionCondition->getConfig();
        }

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
        $variables['showAllowElementsWithoutUri'] = static::supportsUriSelectorCriteria();
        $variables['showLimitSourcesToSectionsWithUri'] = static::supportsSourceUriFiltering();
        $variables['selectionCondition'] = $this->getSelectionConditionBuilderHtml();

        return $variables;
    }

    public function getSelectionCondition(): ?ElementConditionInterface
    {
        if ($this->_selectionCondition !== null && !$this->_selectionCondition instanceof ConditionInterface) {
            $condition = Craft::$app->getConditions()->createCondition($this->_selectionCondition);

            if (!empty($condition->getConditionRules())) {
                $this->_selectionCondition = $condition;
            } else {
                $this->_selectionCondition = null;
            }
        }

        return $this->_selectionCondition;
    }

    public function setSelectionCondition(mixed $condition): void
    {
        if ($condition instanceof ConditionInterface && !$condition->getConditionRules()) {
            $condition = null;
        }

        // Defer instantiation until getSelectionCondition() — same as BaseRelationField.
        $this->_selectionCondition = $condition;
    }

    public function supportsSelectionCondition(): bool
    {
        return $this->createSelectionCondition() !== null;
    }

    public function getSelectionConditionConfig(): ?array
    {
        $condition = $this->getSelectionCondition();

        return $condition ? $condition->getConfig() : null;
    }

    public function getInputHtmlVariables(LinkField $layoutField, HyperField $field): array
    {
        $variables = parent::getInputHtmlVariables($layoutField, $field);

        /** @var ElementInterface|string $elementType */
        $elementType = static::elementType();
        $variables['lowerElementType'] = $elementType::lowerDisplayName();
        $variables['pluralElementType'] = $elementType::pluralLowerDisplayName();
        $variables['applyUriCriteria'] = !$this->allowElementsWithoutUri && static::supportsUriSelectorCriteria();

        return $variables;
    }

    public function getSourceOptions(): array
    {
        $options = [];

        $sources = Craft::$app->getElementSources()->getSources(static::elementType(), 'modal');

        foreach ($sources as $source) {
            if (isset($source['heading'])) {
                continue;
            }

            if ($this->shouldLimitSourcesToElementsWithUri() && !$this->_sourceKeyHasElementUris($source['key'])) {
                continue;
            }

            $options[] = [
                'label' => $source['label'],
                'value' => $source['key'],
            ];
        }

        // Sort alphabetically by label
        usort($options, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        return $options;
    }

    public function getAvailableSources(): string|array|null
    {
        if (!$this->shouldLimitSourcesToElementsWithUri()) {
            return $this->sources;
        }

        if ($this->sources === '*') {
            return array_column($this->getSourceOptions(), 'value') ?: ['-1'];
        }

        if (is_array($this->sources)) {
            $sources = array_values(array_filter(
                $this->sources,
                fn(string $sourceKey): bool => $this->_sourceKeyHasElementUris($sourceKey),
            ));

            return $sources ?: ['-1'];
        }

        return $this->sources;
    }

    public function shouldLimitSourcesToElementsWithUri(): bool
    {
        if ($this->allowElementsWithoutUri || !static::supportsUriSelectorCriteria() || !static::supportsSourceUriFiltering()) {
            return false;
        }

        return $this->limitSourcesToSectionsWithUri;
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
        $targetId = $this->_getLinkTargetId();

        if (!$targetId) {
            $this->_element = null;

            return null;
        }

        if ($this->_element !== null && (int)$this->_element->id === $targetId) {
            return $this->_element;
        }

        $this->_element = null;

        if ($element = $this->_getPrimedElement($status)) {
            return $this->_element = $element;
        }

        $resolutionSiteId = $this->linkSiteId
            ?? $this->ownerSiteId
            ?? Craft::$app->getSites()->getCurrentSite()->id;

        $element = Hyper::$plugin->getMultisiteLinks()->resolveElement(
            static::elementType(),
            $targetId,
            $resolutionSiteId ? (int)$resolutionSiteId : null,
            $status,
            fn(ElementQueryInterface $query, mixed $queryStatus) => $this->modifyElementQuery($query, $queryStatus),
        );

        return $this->_element = $element;
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

        // Layout default (e.g. "Learn More") wins over element title when set.
        if ($default = $this->getLinkTextLayoutDefault()) {
            return $default;
        }

        if ($element = $this->getElement()) {
            return (string)$element;
        }

        return null;
    }


    public function getSelectionConditionBuilderHtml(): ?string
    {
        $selectionCondition = $this->getSelectionCondition() ?? $this->createSelectionCondition();

        if (!$selectionCondition) {
            return null;
        }

        /** @var ElementInterface|string $elementType */
        $elementType = static::elementType();

        // Unique id per link-type row — Hyper fields can have multiple Entry types.
        $handle = $this->handle ?: 'new';
        $selectionCondition->mainTag = 'div';
        $selectionCondition->id = 'selection-condition-' . StringHelper::toKebabCase($handle);
        $selectionCondition->name = 'selectionCondition';
        $selectionCondition->forProjectConfig = true;
        $selectionCondition->queryParams[] = 'site';

        return Cp::fieldHtml($selectionCondition->getBuilderHtml(), [
            'label' => Craft::t('app', 'Selectable {type} Condition', [
                'type' => $elementType::pluralDisplayName(),
            ]),
            'instructions' => StringHelper::upperCaseFirst(Craft::t('app', 'Only allow {type} to be selected if they match the following rules:', [
                'type' => $elementType::pluralLowerDisplayName(),
            ])),
        ]);
    }

    // Protected Methods
    // =========================================================================

    protected function createSelectionCondition(): ?ElementConditionInterface
    {
        return null;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['sources'], 'required', 'when' => function($model) {
            return $model->enabled;
        }];

        $rules[] = [['allowElementsWithoutUri', 'limitSourcesToSectionsWithUri'], 'boolean'];
        $rules[] = [['selectionCondition'], 'safe'];

        return $rules;
    }

    private function _sourceKeyHasElementUris(string $sourceKey): bool
    {
        if ($sourceKey === 'singles') {
            return true;
        }

        if (str_starts_with($sourceKey, 'section:')) {
            $section = Craft::$app->getEntries()->getSectionByUid(substr($sourceKey, 8));

            return !$section || self::_sectionHasUris($section);
        }

        if (str_starts_with($sourceKey, 'group:')) {
            $group = Craft::$app->getCategories()->getGroupByUid(substr($sourceKey, 8));

            return !$group || self::_groupHasUris($group);
        }

        // Unknown/custom sources — keep them visible.
        return true;
    }

    private static function _sectionHasUris(\craft\models\Section $section): bool
    {
        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->hasUrls && trim((string)$siteSettings->uriFormat) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function _groupHasUris(\craft\models\CategoryGroup $group): bool
    {
        foreach ($group->getSiteSettings() as $siteSettings) {
            if ($siteSettings->hasUrls && trim((string)$siteSettings->uriFormat) !== '') {
                return true;
            }
        }

        return false;
    }


    // Private Methods
    // =========================================================================

    private function _getLinkTargetId(): ?int
    {
        $linkValue = $this->linkValue;

        if (is_array($linkValue)) {
            return (int)($linkValue[0] ?? 0) ?: null;
        }

        return (int)$linkValue ?: null;
    }

    private function _getPrimedElement(mixed $status = Element::STATUS_ENABLED): ?ElementInterface
    {
        $targetId = $this->_getLinkTargetId();

        if (!$targetId) {
            return null;
        }

        $targetSiteId = $this->linkSiteId ? (int)$this->linkSiteId : null;
        $candidates = [$targetSiteId];

        if ($targetSiteId === null) {
            $candidates[] = Craft::$app->getSites()->getCurrentSite()->id;
        }

        foreach ($candidates as $siteId) {
            $element = Hyper::$plugin->getLinkRelations()->getPrimedElement($targetId, $siteId ? (int)$siteId : null);

            if ($element && $this->_matchesElementStatus($element, $status)) {
                return $element;
            }
        }

        return null;
    }

    private function _matchesElementStatus(ElementInterface $element, mixed $status): bool
    {
        if ($status === null) {
            return true;
        }

        if ($status === Element::STATUS_ENABLED) {
            return $element->enabled && $element->getEnabledForSite();
        }

        return $element->getStatus() === $status;
    }

}
