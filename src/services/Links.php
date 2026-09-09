<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;
use verbb\hyper\models\LinkInstance;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\errors\MissingComponentException;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Component as ComponentHelper;

class Links extends Component
{
    // Constants
    // =========================================================================

    public const EVENT_REGISTER_LINK_TYPES = 'registerLinkTypes';


    // Public Methods
    // =========================================================================

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
                $version = 0;

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

                if (version_compare($plugin->getVersion(), $version, '<')) {
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

        if ($link instanceof Link) {
            $link->clearContentState();
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

        if (!$prototype) {
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


    // Private Methods
    // =========================================================================

    /**
     * Build a MissingLink that round-trips unknown/disabled type payloads instead of
     * dropping them from the collection on normalize/save.
     */
    private function _createUnsupportedContentLink(HyperField $field, LinkInstance $instance): LinkInterface
    {
        $payload = $instance->toSerialized();
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
