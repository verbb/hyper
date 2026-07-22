# Creating Links Programmatically

Hyper field values are always a **collection of links** — even for single-link fields. Assign an array of link objects (or serialized link arrays), never a bare `Link` instance.

For creating the Hyper **field** itself in a content migration, see [Creating Hyper Fields Programmatically](/guides/developers/creating-hyper-fields).

## Quick start — create an entry with a URL

```php
use craft\elements\Entry;
use verbb\hyper\links\Url;

$entry = new Entry([
    'sectionId' => $section->id,
    'typeId' => $entryType->id,
    'title' => 'Imported page',
]);

$link = new Url();
$link->linkValue = 'https://example.com';
$link->linkText = 'Example';
$link->newWindow = true;

// Always wrap in an array — Hyper expects a collection
$entry->setFieldValue('ctaLink', [$link]);

Craft::$app->getElements()->saveElement($entry);
```

`setFieldValues(['ctaLink' => [$link]])` works the same way.

::: warning Common mistake
Passing a single `Link` object without the array wrapper looks like a native Craft Link field and drops labels / attributes on save:

```php
// Wrong
$entry->setFieldValue('ctaLink', $link);

// Correct
$entry->setFieldValue('ctaLink', [$link]);
```
:::

## Link attributes

Set the same properties you see on the CP Advanced layout tab:

| Property | Role |
| --- | --- |
| `linkValue` | Type-specific target (URL string, entry ID, email, etc.) |
| `linkText` | Author-entered Link Text (label) |
| `newWindow` | Open in a new window (`true` / `false`) |
| `ariaLabel` | `aria-label` |
| `linkTitle` | `title` attribute |
| `urlSuffix` | Query string or fragment (`?utm=…`, `#section`) |
| `classes` | `class` attribute |
| `customAttributes` | Extra HTML attributes |
| `fields` | Custom layout field values keyed by handle |

```php
$link = new \verbb\hyper\links\Url();
$link->linkValue = 'https://example.com/docs';
$link->linkText = 'Documentation';
$link->newWindow = true;
$link->ariaLabel = 'Open documentation in a new tab';
$link->urlSuffix = '?ref=api';
$link->fields = [
    'myCustomField' => 'some value',
];

$entry->setFieldValue('ctaLink', [$link]);
```

Element link types use an element ID (or ID list) as `linkValue`:

```php
$link = new \verbb\hyper\links\Entry();
$link->linkValue = $targetEntry->id; // or [$targetEntry->id]
$link->linkText = 'Learn more'; // optional; falls back to the entry title

$entry->setFieldValue('ctaLink', [$link]);
```

## Array payloads (Matrix, Feed Me–style)

You can pass associative arrays instead of `Link` objects. Identify the link type with its **handle** — the short, stable value shown in the link type’s settings (e.g. `url`, `entry`):

```php
$entry->setFieldValue('ctaLink', [
    [
        'handle' => 'url',
        'linkValue' => 'https://example.com',
        'linkText' => 'Example',
        'newWindow' => true,
    ],
]);
```

Built-in link types use a short handle that matches their name (`url`, `entry`, `email`, `phone`, …). Custom link types generate an editable handle from their label. Open the link type in the field settings and use the **Handle** field’s copy button to get the exact value. You can also pass `type` instead of `handle` using either the handle (`'type' => 'url'`) or the class FQCN (`'type' => \verbb\hyper\links\Url::class`); Hyper resolves it to the field’s configured handle for you.

### Nested in Matrix / Neo

Pass a **plain array of link configs** under the Hyper field handle — do not wrap them in a `links` key:

```php
$entry->setFieldValues([
    'matrixFieldHandle' => [
        'new1' => [
            'type' => 'blockTypeHandle',
            'fields' => [
                'hyperFieldHandle' => [
                    [
                        'handle' => 'entry',
                        'linkValue' => 123,
                        'linkText' => 'Related entry',
                    ],
                ],
            ],
        ],
    ],
]);

Craft::$app->getElements()->saveElement($entry);
```

To clear a nested Hyper field, set an empty array: `'hyperFieldHandle' => []`.

## Multiple links

```php
$entry->setFieldValue('navLinks', [
    $homeLink,
    $aboutLink,
    $contactLink,
]);
```

## Reading the value back

After save (or when reading an existing element), the field value is a [LinkCollection](/reference/link-collection). Single-link fields still expose the first link’s properties via the collection:

```php
$collection = $entry->getFieldValue('ctaLink');

$url = $collection->url;
$text = $collection->text;

foreach ($collection as $link) {
    echo $link->getLink();
}
```
