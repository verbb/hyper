# Creating Hyper Fields Programmatically

A content migration can create a Hyper field as part of a repeatable site setup. This guide creates a single-link field named Button Link, then attaches it to an entry type so you can save and render a link.

Use a development Craft project with Hyper installed and administrative changes allowed. You need an existing entry type, an entry using it and access to the project’s command line and Twig templates. This example uses the field handle `buttonLink`; choose another handle if that field already exists.

## Create a Content Migration

From the Craft project directory, run:

```shell
php craft migrate/create create_button_link
```

Open the generated PHP file in `migrations/`. Keep its generated class name, namespace and `safeDown()` method. Add these imports after the namespace, alongside the existing imports:

```php
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Entry;
use verbb\hyper\links\Url;
```

Replace the generated `safeUp()` method with:

```php
public function safeUp(): bool
{
    if (Craft::$app->getFields()->getFieldByHandle('buttonLink')) {
        throw new \RuntimeException('A field with the handle buttonLink already exists.');
    }

    $field = new HyperField([
        'name' => 'Button Link',
        'handle' => 'buttonLink',
        'linkTypeConfig' => 'custom',
        'defaultLinkType' => Url::typeKey(),
        'multipleLinks' => false,
        'newWindow' => true,
        'linkTypes' => [
            [
                'type' => Url::class,
                'handle' => Url::typeKey(),
                'label' => Url::displayName(),
                'enabled' => true,
            ],
            [
                'type' => Entry::class,
                'handle' => Entry::typeKey(),
                'label' => Entry::displayName(),
                'enabled' => true,
                'sources' => ['*'],
            ],
        ],
    ]);

    if (!Craft::$app->getFields()->saveField($field)) {
        throw new \RuntimeException('Could not save Button Link: ' . json_encode($field->getErrors()));
    }

    return true;
}
```

`linkTypeConfig: custom` keeps these definitions on this field. The two settings arrays enable URL and Entry. Each handle identifies a type within the field; built-in types use short keys such as `url` and `entry`. `defaultLinkType` must match an enabled handle. `sources: ['*']` allows the Entry selector’s available sources; restrict it if editors should use only particular sections.

For shared settings, use a named [Link Type Config](/feature-tour/link-type-configs) instead of passing a private `linkTypes` array. [Link Type Settings](/reference/link-type-settings) describes the definitions in detail.

## Apply and Attach the Field

From the project directory, run the pending content migration:

```shell
php craft migrate/up
```

Review Craft’s list of pending migrations before confirming. Once this migration completes, open **Settings → Fields** and check that Button Link exists with URL and Entry enabled.

Open your entry type under **Settings → Entry Types**, add Button Link to its field layout and save. Field creation alone does not make the field appear on entries; its layout placement does that. The field and layout settings are then part of Project Config.

## Save and Render a Link

Edit your example entry and add a URL link with destination `https://verbb.io` and Link Text `Visit Verbb`. Save the entry. In that entry’s Twig template, add:

```twig
{{ entry.buttonLink.getLink() }}
```

Open the frontend page and confirm that Visit Verbb links to the saved address. Switch the link to Entry, select a published entry with a URL and save again. The rendered link should use that entry’s destination.

If saving the field fails, the migration reports its validation errors. If the field exists but is absent from an editor, check the entry type’s layout. [Creating Links Programmatically](/guides/developers/creating-links-programmatically) covers saving link content from PHP after the field is in place.
