# Events
Hyper provides a collection of events for extending its functionality. Modules and plugins can register event listeners, typically in their `init()` methods, to modify Hyper’s behavior.

## Migration Events
The event that is triggered during a migration, when trying to convert the respective plugin's content model to Hyper's content model. Specifically, when trying to convert one type to another.

```php
use verbb\hyper\events\ModifyMigrationLinkEvent;
use verbb\hyper\migrations\MigrateLinkit;
use yii\base\Event;

Event::on(MigrateLinkit::class, MigrateLinkit::EVENT_MODIFY_LINK_TYPE, function(ModifyMigrationLinkEvent $event) {
    $oldClass = $event->oldClass;
    $newClass = $event->newClass;
    // ...
});
```
