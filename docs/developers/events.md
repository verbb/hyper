# Events

Hyper provides a collection of events for extending its functionality. Modules and plugins can register event listeners, typically in their `init()` methods, to modify Hyper’s behaviour.

For registering a new Hyper link type, follow [Creating Link Types](/developers/creating-link-types). This page covers mapping source types during conversion.

<span id="map-a-custom-source-type"></span>

## Migration Events

### The `modifyLinkType` Event
The event that is triggered when a migration maps a source link type to a Hyper link type. Register the listener for both the field and content migration classes so both stages use the same mapping. The listener must be available to control-panel and console migrations.

Suppose a Typed Link extension stores its type identifier as `my-custom-type`, and its value is a URL compatible with Hyper’s URL type. The following listener maps that identifier while converting both the field definition and its content.

```php
use verbb\hyper\events\ModifyMigrationLinkEvent;
use verbb\hyper\links\Url;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateTypedLinkField;
use yii\base\Event;

$mapType = static function(ModifyMigrationLinkEvent $event): void {
    if ($event->oldClass === 'my-custom-type') {
        $event->newClass = Url::class;
    }
};

Event::on(
    MigrateTypedLinkField::class,
    MigrateTypedLinkField::EVENT_MODIFY_LINK_TYPE,
    $mapType,
);
Event::on(
    MigrateTypedLinkContent::class,
    MigrateTypedLinkContent::EVENT_MODIFY_LINK_TYPE,
    $mapType,
);
```

Replace `my-custom-type` with the actual identifier from your source extension. `oldClass` identifies the source type; `newClass` is the Hyper class to use. Mapping the class does not convert an arbitrary custom payload into a URL, so confirm that the source value is compatible before choosing `Url`.

Run the [Typed Link conversion](/guides/migrations-upgrades/migrating-from-typed-link) on sample content containing that custom type. The field definition and saved link should both resolve to URL. If only the field or only the content changes, check that your listener is registered on both migration classes.

<span id="other-sources"></span>

The same event is available on the field and content migration classes for Linkit and Craft Link. Use the classes for the source you are converting. Typed Link also has a separate legacy field step where applicable. See [Migration Events](/reference/migration-tools#type-mapping) for the class names and event properties.
