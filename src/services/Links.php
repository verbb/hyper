<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\base\ElementLink;
use verbb\hyper\links as linkTypes;

use Craft;
use craft\base\Component;
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
            linkTypes\Category::class,
            linkTypes\Custom::class,
            linkTypes\Email::class,
            linkTypes\Embed::class,
            linkTypes\Entry::class,
            linkTypes\FormieForm::class,
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

}
