# Creating Hyper Fields Programmatically

Use this pattern in Craft **content migrations** or boilerplate seeders when you need to create a Hyper field with `new HyperField([])` and save it via `Craft::$app->getFields()->saveField()`.

::: tip Prefer configs for reuse
For shared link types across many fields, pick a named [Link Type Config](/feature-tour/link-type-configs). Set `linkTypeConfig` to `custom` and pass `linkTypes` only when the field should be unique.
:::

## Required link type keys

Each entry in `linkTypes` must be a **settings array** (not a bare `new Url()` instance). At minimum include:

| Key | Required | Description |
| --- | --- | --- |
| `type` | Yes | FQCN of the link class (`verbb\hyper\links\Url`, …) |
| `handle` | Yes | Stable handle — usually `default-` + kebab-cased FQCN |
| `label` | Yes | Label shown in the type dropdown |
| `enabled` | No | Default `true` |
| Type-specific settings | No | e.g. `sources` for element types |

Passing `new Entry()` / `new Url()` objects without `label` and `handle` writes incomplete project config and breaks the CP type dropdown.

## Example

```php
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Asset;
use verbb\hyper\links\Entry;
use verbb\hyper\links\Url;

$field = new HyperField([
    'groupId' => $fieldGroup->id ?? null,
    'name' => 'Button Link',
    'handle' => 'buttonLink',
    'instructions' => '',
    'required' => false,
    'linkTypeConfig' => 'custom',
    'defaultLinkType' => Url::typeKey(), // 'url'
    'multipleLinks' => false,
    'newWindow' => true,
    'linkTypes' => [
        [
            'type' => Url::class,
            'enabled' => true,
            'label' => Url::displayName(),
            'handle' => Url::typeKey(),
        ],
        [
            'type' => Entry::class,
            'enabled' => true,
            'label' => Entry::displayName(),
            'handle' => Entry::typeKey(),
            'sources' => ['*'], // or ['section:…'] UIDs
        ],
        [
            'type' => Asset::class,
            'enabled' => true,
            'label' => Asset::displayName(),
            'handle' => Asset::typeKey(),
            'sources' => [
                'volume:' . $volume->uid,
            ],
        ],
    ],
]);

if (!Craft::$app->getFields()->saveField($field)) {
    throw new \RuntimeException('Could not save Hyper field: ' . json_encode($field->getErrors()));
}
```

Disable unused types with `'enabled' => false` rather than omitting them, if you want the same set of handles across environments.

## Handles and `defaultLinkType`

- Built-in link types default to their short **type key** as the handle — `Url::typeKey()` is `url`, `Entry::typeKey()` is `entry`, and so on.
- `defaultLinkType` must match an **enabled** link type’s `handle`.
- You can assign your own handles for custom link types; keep them stable so content migrations and programmatic saves stay aligned.

## After save

Field settings land in project config under `fields.{uid}`. Link type field layouts get UIDs on first save; you usually do not need to set `layoutUid` / `layoutConfig` yourself for boilerplate fields.