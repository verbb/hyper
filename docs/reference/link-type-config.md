# Link Type Config

A named link type config holds reusable link definitions and layouts. Fields refer to its UID; the service also accepts its handle when selecting a config in PHP. See [Link Type Configs](/feature-tour/link-type-configs) for the control-panel workflow.

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

The field and config are saved, but the field still needs adding to the relevant element layout before editors can use it. The stock set contains the available registered link types; inspect and configure their enabled states for your site.

## Field Methods

::: reference
### `enableCustomLinkTypes()`

**Behaviour:** Select Custom and copy the currently selected shared definitions into the field.

Select Custom and copy the currently selected shared definitions into the field.
:::

::: reference
### `useLinkTypeConfig($uidOrHandle)`

**Behaviour:** Select a named config by UID or handle.

Select a named config by UID or handle.
:::

::: reference
### `getLinkTypeDefinitions()`

**Returns:** `array` · **Behaviour:** Read the field’s resolved definitions.

Read the field’s resolved definitions.
:::

::: reference
### `hasCustomLinkTypes()`

**Behaviour:** Check whether the field manages its own types.

Check whether the field manages its own types.
:::


Save the field after changing its selection in PHP. Keep config and link type handles stable when other code refers to them.

## Storage and Deletion

Shared definitions are stored under `plugins.hyper.linkTypeConfigs` in Project Config, keyed by config UID. A field using a shared config does not store its own `linkTypes` array. Custom fields keep their definitions in their own settings.

The Default config cannot be deleted. A named config cannot be deleted while a Hyper field uses it. Its model’s `canDelete()` method reports whether deletion is available.
