# Events
Hyper provides a collection of events for extending its functionality. Modules and plugins can register event listeners, typically in their `init()` methods, to modify Hyper’s behavior.

## Migration Events
The event that is triggered during a migration, when trying to convert the respective plugin's content model to Hyper's content model. Specifically, when trying to convert one type to another.

Register on **both** the field and content migration classes for a source (and on Typed Link’s legacy step when needed).

```php
use verbb\hyper\events\ModifyMigrationLinkEvent;
use verbb\hyper\migrations\MigrateCraftLinkContent;
use verbb\hyper\migrations\MigrateCraftLinkField;
use verbb\hyper\migrations\MigrateLinkitContent;
use verbb\hyper\migrations\MigrateLinkitField;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\migrations\MigrateTypedLinkField;
use verbb\hyper\links\Url;
use yii\base\Event;

// Linkit
Event::on(MigrateLinkitContent::class, MigrateLinkitContent::EVENT_MODIFY_LINK_TYPE, function(ModifyMigrationLinkEvent $event) {
    // …
});

Event::on(MigrateLinkitField::class, MigrateLinkitField::EVENT_MODIFY_LINK_TYPE, function(ModifyMigrationLinkEvent $event) {
    // …
});

// Typed Link — custom type handles
Event::on(MigrateTypedLinkField::class, MigrateTypedLinkField::EVENT_MODIFY_LINK_TYPE, function(ModifyMigrationLinkEvent $event) {
    if ($event->oldClass === 'my-custom-type') {
        $event->newClass = Url::class;
    }
});

Event::on(MigrateTypedLinkContent::class, MigrateTypedLinkContent::EVENT_MODIFY_LINK_TYPE, function(ModifyMigrationLinkEvent $event) {
    if ($event->oldClass === 'my-custom-type') {
        $event->newClass = Url::class;
    }
});

// Craft native Link — type IDs are strings like `entry`, `url`
Event::on(MigrateCraftLinkField::class, MigrateCraftLinkField::EVENT_MODIFY_LINK_TYPE, function(ModifyMigrationLinkEvent $event) {
    // …
});

Event::on(MigrateCraftLinkContent::class, MigrateCraftLinkContent::EVENT_MODIFY_LINK_TYPE, function(ModifyMigrationLinkEvent $event) {
    // …
});
```
