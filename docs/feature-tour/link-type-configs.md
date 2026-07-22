# Link Type Configs

Hyper ships **named link type configs** — reusable suites of link types, labels, enabled state, and field layouts that any number of fields can share. Each field either points at a named config or switches to **Custom** to define its own private suite.

## Behavior

When a field uses a **named Link Type Config**, it stores no `linkTypes` of its own. Instead, its link types are resolved at runtime from the shared config in project config (`plugins.hyper.linkTypeConfigs.{uid}`). Because the field only references the config, editing that config updates every field pointing at it — which is what makes configs useful for keeping many fields consistent.

Choosing **Custom** flips this around: the field owns its own `linkTypes`, and any edits stay on that one field without affecting anything else.

New Hyper fields start on the **Default** config. Existing fields that already have stored `linkTypes` are treated as Custom and are left untouched — there’s no migration that folds them into a shared config.

## Stock Default config

On first boot, Hyper seeds a **Default** config from the historical new-field suite:

- Enabled: URL, Entry, Category, Email, and other types historically on by default
- Disabled: Asset, Custom, Embed, Passive, Phone, Site, User

Create additional configs (e.g. Simple, Advanced) under Settings for different field roles.

## Control panel

**Settings → Link Type Configs** — list, create, edit, delete. Each config uses the same link-type configurator as field settings.

On each Hyper field, **Custom** is always the final **Link Type Config** option. Choosing it reveals the field-owned Link Types editor.

## Project config

```yaml
plugins:
  hyper:
    linkTypeConfigs:
      {uid}:
        name: Default
        handle: default
        linkTypes:
          - type: verbb\hyper\links\Url
            handle: url
            label: URL
            enabled: true
            layoutUid: …
            layoutConfig: …
```

## PHP

```php
use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkTypeConfig;

$configs = Hyper::$plugin->getLinkTypeConfigs();

// Create / update a config
$config = new LinkTypeConfig([
    'name' => 'Simple',
    'handle' => 'simple',
    'linkTypes' => $configs->createStockSerializedLinkTypes(),
]);
$configs->saveConfig($config);

$field = new HyperField([
    'name' => 'CTA',
    'handle' => 'cta',
    'linkTypeConfig' => 'simple', // live share
]);

$field->enableCustomLinkTypes(); // selects Custom and copies the selected config
$field->useLinkTypeConfig('default'); // back to shared Default
```

Custom programmatic fields:

```php
$field = new HyperField([
    'name' => 'Nav',
    'handle' => 'nav',
    'linkTypeConfig' => 'custom',
    'linkTypes' => [/* … */],
]);
```
