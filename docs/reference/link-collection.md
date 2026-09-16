# LinkCollection

The field value returned by a Hyper field is a `LinkCollection` — an iterable collection of [Link](/reference/link) objects read from the owning element’s saved field content.

Single-link and multi-link fields share the same class; behaviour differs based on the field’s **Enable Multiple Links** setting.

## Single-Link Fields

The collection’s property and method shortcuts use its first link, including on multi-link fields:

```twig
{# These are equivalent on a single-link field #}
{{ entry.myLinkField.url }}
{{ entry.myLinkField.first().url }}
```

Link property access, forwarded method calls and string conversion use the first link. Collection methods such as `count()` and `isEmpty()` follow the field’s single-link or multi-link behaviour. Use `first()` when you need an explicit `Link` object or `null`.

## Multi-Link Fields

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

`count()` returns the number of rows for multi-link fields. For single-link fields, it returns `0` for an empty first link and `1` for a non-empty first link. `isEmpty()` checks the first link on single-link fields and all links on multi-link fields.

## Methods

::: reference
### `first()`

**Returns:** `verbb\hyper\base\LinkInterface|null`

The first hydrated link, or `null`.
:::

::: reference
### `getLinks()`

**Returns:** `array`

All hydrated links as an array.
:::

::: reference
### `isEmpty()`

**Returns:** `bool`

Whether the field has no meaningful link content. For multi-link fields, returns `false` if **any** link is non-empty. Respects link text, classes, custom fields, and type-specific empty rules — not just URL presence.
:::

::: reference
### `getUrl()`

**Returns:** `string|null`

URL of the first link.
:::

::: reference
### `getText()`

**Returns:** `string|null`

Text of the first link.
:::

::: reference
### `getTarget()`

**Returns:** `string|null`

Target of the first link.
:::

::: reference
### `getLinkText()`

**Returns:** `string|null`

Link Text field value of the first link (with element title fallback where applicable).
:::

::: reference
### `getCustomLinkText()`

**Returns:** `string|null`

Author-entered Link Text only on the first link (`null` when blank).
:::

::: reference
### `serializeValues()`

**Returns:** `array`

Serialise all links for save/propagation (used internally).
:::

::: reference
### `withLinks(array $links)`

**Returns:** `self`

Return a **new** collection with the given links; does not mutate the original. Implements `LinkCollectionInterface`.
:::


## Interface

Service code can type-hint `LinkCollectionInterface` for `getLinks()`, `first()`, `isEmpty()`, `serializeValues()`, and `withLinks()`.

In a service, obtain the collection from an existing element with `$entry->getFieldValue('myLinkField')`. To replace its links, pass an array of configured Link objects to `withLinks()`, then assign the returned collection to the element and save it. See [Creating Links Programmatically](/guides/developers/creating-links-programmatically) for constructing and saving those objects.
