<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\base\ElementLink;
use verbb\hyper\links as linkTypes;

use craft\base\Component;
use craft\errors\MissingComponentException;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Component as ComponentHelper;

class Links extends Component
{
    // Static Methods
    // =========================================================================

    public static function createLink(mixed $config): LinkInterface
    {
        if (is_string($config)) {
            $config = ['type' => $config];
        }

        try {
            $link = ComponentHelper::createComponent($config, LinkInterface::class);

            // Check if the element class exists, in case the third-party plugin was uninstalled
            if ($link instanceof ElementLink && !class_exists($link::elementType())) {
                throw new MissingComponentException();
            }
        } catch (MissingComponentException $e) {
            $config['errorMessage'] = $e->getMessage();
            $config['expectedType'] = $config['type'];
            unset($config['type']);

            $link = new linkTypes\MissingLink($config);
        }

        return $link;
    }


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
            linkTypes\Phone::class,
            linkTypes\Site::class,
            linkTypes\Url::class,
            linkTypes\User::class,
        ];

        if (Hyper::$plugin->getService()->isPluginInstalledAndEnabled('commerce')) {
            $linkTypes[] = linkTypes\Product::class;
            $linkTypes[] = linkTypes\Variant::class;
        }

        if (Hyper::$plugin->getService()->isPluginInstalledAndEnabled('shopify')) {
            $linkTypes[] = linkTypes\ShopifyProduct::class;
        }

        $event = new RegisterComponentTypesEvent([
            'types' => $linkTypes,
        ]);
        $this->trigger(self::EVENT_REGISTER_LINK_TYPES, $event);

        return $event->types;
    }

}
