# Link Type Config

A named link type config holds reusable link definitions and layouts. Fields refer to its UID, and the service also accepts its handle when selecting a config in PHP. See [Link Type Configs](/feature-tour/link-type-configs) for the control-panel workflow.

## Create and Select a Config

This example belongs inside a Craft content migration or module action in an environment that allows administrative changes. Place the imports at the top of the PHP file. Run it once to create a config with the handle `pageLinks`:

```php
use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkTypeConfig;

$configs = Hyper::$plugin->getLinkTypeConfigs();
$config = new LinkTypeConfig([
    'name' => 'Page Links',
    'handle' => 'pageLinks',
    'linkTypes' => $configs->createStockSerializedLinkTypes(),
]);
if (!$configs->saveConfig($config)) {
    throw new \RuntimeException(json_encode($config->getErrors()));
}

$field = new HyperField([
    'name' => 'Page Link',
    'handle' => 'pageLink',
    'linkTypeConfig' => $config->uid,
]);
if (!Craft::$app->getFields()->saveField($field)) {
    throw new \RuntimeException(json_encode($field->getErrors()));
}
```

The field and config are saved, but the field still needs adding to the relevant element layout before editors can use it. The stock set contains the available registered link types, so inspect and configure their enabled states for your site.

## Field Methods

These methods are on the Hyper field (`verbb\hyper\fields\HyperField`), for choosing where the field gets its link types.

::: reference
### `enableCustomLinkTypes()`

**Returns:** `void`

Switches the field to Custom link types, starting from a copy of the shared config it currently uses. If the field already uses Custom link types, its definitions are replaced with a copy of the Default config.
:::

::: reference
### `useLinkTypeConfig()`

**Arguments:** `$handle` (`string|null`, optional), the UID or handle of a named config.

**Returns:** `void`

Switches the field to a named config. Passing null or `custom` selects the Default config.
:::

::: reference
### `getLinkTypeDefinitions()`

**Returns:** `array`

Returns the link type definitions the field uses, from its shared config or its own Custom settings.
:::

::: reference
### `hasCustomLinkTypes()`

**Returns:** `bool`

Returns whether the field manages its own link types instead of using a shared config.
:::

Save the field after changing its selection in PHP. Keep config and link type handles stable when other code refers to them.

## Storage and Deletion

Shared definitions are stored under `plugins.hyper.linkTypeConfigs` in Project Config, keyed by config UID. A field using a shared config does not store its own `linkTypes` array. Custom fields keep their definitions in their own settings.

The Default config cannot be deleted. A named config cannot be deleted while a Hyper field uses it. Its model’s `canDelete()` method reports whether deletion is available.
