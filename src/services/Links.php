<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\base\Link;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;
use verbb\hyper\models\LinkInstance;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\errors\MissingComponentException;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\Component as ComponentHelper;

class Links extends Component
{
    // Static Methods
    // =========================================================================

    public static function sortLinkTypeSettings(array $configs): array
    {
        $ordered = [];

        foreach ($configs as $key => $config) {
            $order = is_int($key) ? $key : count($ordered);

            if (is_array($config)) {
                $order = (int)ArrayHelper::remove($config, 'sortOrder', $order);
            }

            $ordered[] = ['order' => $order, 'config' => $config];
        }

        // Ordering metadata is not identity: equal positions must retain both rows.
        // PHP's stable sort keeps their input order when positions are tied.
        usort($ordered, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_column($ordered, 'config');
    }


    // Constants
    // =========================================================================

    public const EVENT_REGISTER_LINK_TYPES = 'registerLinkTypes';


    // Properties
    // =========================================================================

    private bool $_disabledTypesUnsupported = false;
    private bool $_firstOfClassLegacyHandles = false;
    private bool $_clearedLinksKept = false;


    // Public Methods
    // =========================================================================

    /**
     * Run a callback with saved links of disabled types hydrated as unsupported, as in 3.0.0–3.0.7.
     *
     * @internal Recomputes fingerprints that those releases recorded for draft conflict checks.
     */
    public function withDisabledTypesUnsupported(callable $callback): mixed
    {
        $previous = $this->_disabledTypesUnsupported;
        $this->_disabledTypesUnsupported = true;

        try {
            return $callback();
        } finally {
            $this->_disabledTypesUnsupported = $previous;
        }
    }

    /**
     * Run a callback with Hyper 1/2 `default-` handles resolved to the first type of their class,
     * as releases up to 3.1.0 did, even when that is a custom type sorted above the built-in one.
     *
     * @internal Recomputes fingerprints that those releases recorded for draft conflict checks.
     */
    public function withFirstOfClassLegacyHandles(callable $callback): mixed
    {
        $previous = $this->_firstOfClassLegacyHandles;
        $this->_firstOfClassLegacyHandles = true;

        try {
            return $callback();
        } finally {
            $this->_firstOfClassLegacyHandles = $previous;
        }
    }

    /**
     * Run a callback with cleared links kept when serializing, as releases before 3.2.0 did.
     *
     * @internal Recomputes fingerprints that those releases recorded for draft conflict checks.
     */
    public function withClearedLinksKept(callable $callback): mixed
    {
        $previous = $this->_clearedLinksKept;
        $this->_clearedLinksKept = true;

        try {
            return $callback();
        } finally {
            $this->_clearedLinksKept = $previous;
        }
    }

    public function getAllLinkTypes(): array
    {
        $linkTypes = [
            linkTypes\Asset::class,
            linkTypes\CalendarEvent::class,
            linkTypes\Category::class,
            linkTypes\Custom::class,
            linkTypes\Email::class,
            linkTypes\Embed::class,
            linkTypes\Entry::class,
            linkTypes\FormieForm::class,
            linkTypes\Passive::class,
            linkTypes\Phone::class,
            linkTypes\Product::class,
            linkTypes\Site::class,
            linkTypes\ShopifyProduct::class,
            linkTypes\Url::class,
            linkTypes\User::class,
            linkTypes\Variant::class,
        ];

        $event = new RegisterComponentTypesEvent([
            'types' => $linkTypes,
        ]);
        $this->trigger(self::EVENT_REGISTER_LINK_TYPES, $event);

        // Ensure all required plugins are enabled at the provided version or above
        foreach ($event->types as $linkTypeKey => $linkType) {
            foreach ($linkType::getRequiredPlugins() as $handle) {
                $version = null;

                if (is_array($handle)) {
                    $version = $handle['version'] ?? $version;
                    $handle = $handle['handle'] ?? '';
                }

                if (!Hyper::$plugin->getService()->isPluginInstalledAndEnabled($handle)) {
                    unset($event->types[$linkTypeKey]);

                    continue;
                }

                $plugin = Craft::$app->getPlugins()->getPlugin($handle);

                if (!$plugin) {
                    unset($event->types[$linkTypeKey]);

                    continue;
                }

                if ($version !== null && version_compare($plugin->getVersion(), $version, '<')) {
                    unset($event->types[$linkTypeKey]);
                }
            }
        }

        return array_values($event->types);
    }

    public function createLink(mixed $config): LinkInterface
    {
        if (is_string($config)) {
            $config = ['type' => $config];
        }

        try {
            $link = ComponentHelper::createComponent($config, LinkInterface::class);

            // Check if this is a registered class. While a third-party-supported class might exist,
            // the plugin could be uninstalled or the wrong version.
            if (!in_array($config['type'], $this->getAllLinkTypes())) {
                throw new MissingComponentException("`{$config['type']}` is not a supported link type.");
            }
        } catch (MissingComponentException $e) {
            $config['errorMessage'] = $e->getMessage();
            $config['expectedType'] = $config['type'];
            unset($config['type']);

            $link = new linkTypes\MissingLink($config);
        }

        return $link;
    }

    public function createSettingsPrototype(mixed $config): LinkInterface
    {
        $link = $this->createLink($config);
        $settings = is_array($config) ? ComponentHelper::mergeSettings($config) : [];

        if ($link instanceof Link) {
            $link->clearContentState();
            // Supply the same defaults as the settings UI for programmatic configs.
            // Explicit empty values remain invalid and are handled by settings rules.
            $link->label ??= $settings['label'] ?? $link::displayName();
            $link->handle ??= $settings['handle'] ?? $link::typeKey();
            $link->setScenario(Link::SCENARIO_SETTINGS);
        }

        return $link;
    }

    public function createLinkFromSerialized(HyperField $field, array $data): ?LinkInterface
    {
        $instance = LinkInstance::fromSerialized($data, $field);

        return $this->createLinkFromInstance($field, $instance);
    }

    public function createLinkFromInstance(HyperField $field, LinkInstance $instance): ?LinkInterface
    {
        $requestedHandle = $instance->linkTypeHandle;
        $prototype = $field->getLinkTypeByHandle($requestedHandle);

        // Only a legacy handle without an exact match resolves differently in this mode.
        if ($this->_firstOfClassLegacyHandles && $prototype && $prototype->handle !== $requestedHandle && str_starts_with((string)$requestedHandle, 'default-')) {
            $prototype = LinkTypeConfigs::findLegacyStockLinkType($field->getLinkTypes(), $requestedHandle, true) ?? $prototype;
        }

        // Disabling a type only stops new links of it. Saved links keep resolving as that type.
        if (!$prototype || $prototype instanceof linkTypes\MissingLink || ($this->_disabledTypesUnsupported && !$prototype->enabled)) {
            // Retain opaque unresolved content through read/serialize (Astra H3-A07).
            return $this->_createUnsupportedContentLink($field, $instance);
        }

        // Canonicalize identity at the hydration boundary (Astra H3-A08): legacy
        // `default-<kebab-fqcn>` aliases resolve to the stock prototype, then runtime
        // + serialize use the field’s configured handle (e.g. `url`).
        if ($prototype->handle && $instance->linkTypeHandle !== $prototype->handle) {
            $instance->linkTypeHandle = (string)$prototype->handle;
        }

        $link = clone $prototype;
        $link->field = $field;
        $link->populateFromInstance($instance);

        // Ensure handle stays canonical even if populate re-applied a stored alias.
        if ($prototype->handle) {
            $link->handle = (string)$prototype->handle;
        }

        return $link;
    }

    public function createDefaultContentLink(HyperField $field, ?string $handle = null): ?LinkInterface
    {
        $handle ??= $field->defaultLinkType;

        if (!$handle) {
            return null;
        }

        $instance = new LinkInstance();
        $instance->linkTypeHandle = $handle;
        $instance->newWindow = $field->defaultNewWindow;

        // Seed Link Text from the layout default when present.
        $prototype = $field->getLinkTypeByHandle($handle);

        if ($prototype && ($layout = $prototype->getFieldLayout()) && $layout->isFieldIncluded('linkText')) {
            $linkTextElement = $layout->getField('linkText');

            if (
                $linkTextElement instanceof \verbb\hyper\fieldlayoutelements\LinkTextField
                && $linkTextElement->defaultValue !== null
                && $linkTextElement->defaultValue !== ''
            ) {
                $instance->linkText = $linkTextElement->defaultValue;
            }
        }

        // Seed URL default / fixed values.
        if (
            $prototype instanceof \verbb\hyper\links\Url
            && $prototype->defaultLinkValue !== null
            && $prototype->defaultLinkValue !== ''
        ) {
            $instance->linkValue = $prototype->defaultLinkValue;
        }

        $link = $this->createLinkFromInstance($field, $instance);

        if ($link) {
            $link->isNew = true;
            $link->newWindow = $field->defaultNewWindow;
        }

        return $link;
    }

    public function resolveUrl(HyperField $field, LinkInstance $instance, ?int $siteId = null): ?string
    {
        $link = $this->createLinkFromInstance($field, $instance);

        if (!$link) {
            return null;
        }

        if ($link instanceof ElementLink && $siteId && !$link->linkSiteId) {
            $link->linkSiteId = $siteId;
        }

        return $link->getUrl();
    }

    public function isInstanceEmpty(LinkInterface|string $linkType, LinkInstance $instance): bool
    {
        $class = is_string($linkType) ? $linkType : $linkType::class;

        if (is_subclass_of($class, Link::class)) {
            return $class::isInstanceEmpty($instance);
        }

        return !$instance->hasLinkValue() && !$instance->hasMeaningfulAttributes();
    }

    /**
     * Whether a link was cleared: it has no destination and nothing an editor entered. Saving drops it.
     *
     * @internal
     */
    public function isLinkCleared(HyperField $field, LinkInterface $link): bool
    {
        if ($this->_clearedLinksKept || !$link instanceof Link || $link instanceof linkTypes\MissingLink) {
            return false;
        }

        // A synced list keeps the same links on every site, including a deliberately blank translation.
        if ($field->multipleLinks && $field->syncLinksAcrossSites && $field->translationMethod === Field::TRANSLATION_METHOD_SITE) {
            return false;
        }

        $instance = $link->toInstance();

        // The CP posts every link's new window toggle, so a choice matching the field default isn't content.
        if ($instance->newWindow === ($field->newWindow && $field->defaultNewWindow)) {
            $instance->newWindow = null;
        }

        return $link::isInstanceEmpty($instance);
    }


    // Private Methods
    // =========================================================================

    /**
     * Build a MissingLink that round-trips unknown/disabled type payloads instead of
     * dropping them from the collection on normalize/save.
     */
    private function _createUnsupportedContentLink(HyperField $field, LinkInstance $instance): LinkInterface
    {
        // Keep raw records before the DTO's typed projection can discard extension data.
        $payload = $instance->getOriginalPayload();
        $handle = $instance->linkTypeHandle !== '' ? $instance->linkTypeHandle : 'missing';

        $link = new linkTypes\MissingLink([
            'handle' => $handle,
            'expectedType' => $handle,
            'errorMessage' => Craft::t('hyper', 'Unsupported or missing link type “{handle}”.', [
                'handle' => $handle,
            ]),
            'field' => $field,
            'uid' => $instance->uid,
            'newWindow' => $instance->newWindow,
            'linkValue' => $instance->linkValue,
            'linkText' => $instance->linkText,
            'ariaLabel' => $instance->ariaLabel,
            'urlSuffix' => $instance->urlSuffix,
            'linkTitle' => $instance->linkTitle,
            'classes' => $instance->classes,
            'customAttributes' => $instance->customAttributes,
        ]);

        $link->setOpaqueSerializedPayload($payload);

        return $link;
    }
}
