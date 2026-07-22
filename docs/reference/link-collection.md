# LinkCollection

The field value returned by a Hyper field is a `LinkCollection` — an iterable collection of hydrated [Link](/reference/link) objects built from serialized content on the owner element.

Single-link and multi-link fields share the same class; behaviour differs based on the field’s **Enable Multiple Links** setting.

## Single-link fields

When multiple links are disabled, the collection **delegates** to the first link for convenience:

```twig
{# These are equivalent on a single-link field #}
{{ entry.myLinkField.url }}
{{ entry.myLinkField.first().url }}
```

Property access, `__call`, and `__toString` on the collection forward to `first()`. Use `first()` when you need an explicit `Link` object or `null`.

## Multi-link fields

Iterate the collection directly:

```twig
{% for link in entry.myLinkField %}
    {{ link.getLink() }}
{% endfor %}
```

Or use array access:

```twig
{{ entry.myLinkField[0].url }}
{{ entry.myLinkField[1].text }}
```

`count()` returns the number of link rows. Each link is evaluated independently for `isEmpty()`.

## Methods

Method | Description
--- | ---
`first()` | The first hydrated link, or `null`.
`getLinks()` | All hydrated links as an array.
`isEmpty()` | Whether the field has no meaningful link content. For multi-link fields, returns `false` if **any** link is non-empty. Respects link text, classes, custom fields, and type-specific empty rules — not just URL presence.
`getUrl()` | URL of the first link.
`getText()` | Text of the first link.
`getTarget()` | Target of the first link.
`getLinkText()` | Link Text field value of the first link (with element title fallback where applicable).
`getCustomLinkText()` | Author-entered Link Text only on the first link (`null` when blank).
`serializeValues()` | Serialize all links for save/propagation (used internally).
`withLinks(array $links)` | Return a **new** collection with the given links; does not mutate the original. Implements `LinkCollectionInterface`.

## Interface

Service code can type-hint `LinkCollectionInterface` for `getLinks()`, `first()`, `isEmpty()`, `serializeValues()`, and `withLinks()`.

```php
use verbb\hyper\models\LinkCollectionInterface;

/** @var LinkCollectionInterface $links */
$links = $entry->getFieldValue('myLinkField');
$updated = $links->withLinks([$newLinkA, $newLinkB]);
```
