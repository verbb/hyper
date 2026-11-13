# Link Collection

A Hyper field returns a `LinkCollection` in both single-link and multiple-link mode. Use it to render the first link, loop over links, or select links by their type and custom fields. **Enable Multiple Links** controls authoring, and the template API stays the same.

The examples below belong in an entry’s Twig template and assume a Hyper field with the handle `myLinkField`.

## Render Links

Render the first non-empty link:

```twig
{{ entry.myLinkField.getLink() }}
```

Render every non-empty link in the saved order:

```twig
{% for link in entry.myLinkField %}
    {{ link.getLink() }}
{% endfor %}
```

A link is empty when it cannot resolve a non-blank URL. Labels, classes and custom fields alone do not give it a destination. Links to unavailable entries and Passive labels are empty under this definition. Hyper resolves the destination locally and doesn’t request an external website to check whether it exists.

Iteration, array access, `first()`, `count()`, `exists()` and rendering shortcuts all use the current selection. Loop metadata such as `loop.length` and `loop.last` describes that selection. With no matches, `first()` returns `null`, `getLink()` outputs nothing and a loop’s `{% else %}` branch runs.

## Filter Links

Suppose you have a custom URL type with the handle `externalResource`, with a Lightswitch field called `featured` in its layout. Display only featured resources:

```twig
{% for link in entry.myLinkField.where({ handle: 'externalResource', featured: true }) %}
    {{ link.getLink() }}
{% endfor %}
```

`handle` identifies a configured link type, so two custom URL types can be selected independently. The native `type` attribute contains the PHP class name. For example, `{ type: 'verbb\\hyper\\links\\Url' }` selects URL links across their configured handles.

Conditions can read native link attributes and normalised custom fields by handle. Prefix a custom field handle with `fields.` when it overlaps a native attribute, such as `'fields.title'`. A field absent from a link’s layout reads as `null`. Comparisons support text, numbers, booleans and date values. Relation queries and complex field values need their own field APIs rather than scalar comparisons.

For example, with a Number field called `priority` on that type:

```twig
{% set resources = entry.myLinkField
    .where({ handle: 'externalResource' })
    .andWhere(['>=', 'priority', 10])
    .orderBy('priority DESC')
    .limit(3) %}

{% for link in resources %}
    {{ link.getLink() }}
{% endfor %}
```

Filters return a new selection and leave the original collection unchanged. `where()` replaces the ordinary conditions, while `andWhere()` and `orWhere()` combine them. The empty-link scope is separate, so replacing conditions or adding an OR branch does not include empty links accidentally. Equal sort values keep their saved order, and overlapping OR branches return each occurrence once without collapsing repeated links.

You can iterate a selection directly. Call `all()` when another API needs an array. It returns the same links. No `query()` call is required.

### Empty Links

Use `empty()` to control which destinations are included:

| Selection | Result |
| --- | --- |
| `empty(false)` | Links with a resolved URL. This is the default. |
| `empty(true)` | Links without a resolved URL. |
| `empty(null)` | Every link, regardless of its destination. |

To inspect unfinished links and their entered labels:

```twig
{% for link in entry.myLinkField.empty(true) %}
    <p>{{ link.customLinkText ?? 'Unlabelled link' }}</p>
{% endfor %}
```

A selection containing empty links is still a non-empty collection: `empty(true).isEmpty()` answers whether that selection has any rows. On an individual Link, `isEmpty()` answers whether that link has a destination.

Passive labels also need explicit inclusion. [Rendering Links](/feature-tour/rendering-links#passive-labels) shows a list containing both anchors and labels.

### Condition Operators

Hash conditions combine attributes with AND. An array value is an IN condition: `{ handle: ['url', 'externalResource'] }`. Operator conditions use an array beginning with the operator:

| Operator | Example |
| --- | --- |
| `and`, `or` | `['or', { handle: 'url' }, { handle: 'externalResource' }]` |
| `not` | `['not', { handle: 'url' }]` |
| `=`, `!=`, `>`, `>=`, `<`, `<=` | `['>=', 'priority', 10]` |
| `===`, `!==` | `['===', 'featured', true]` |
| `in`, `not in` | `['in', 'priority', [10, 20]]` |
| `between`, `not between` | `['between', 'priority', 10, 20]` |
| `like`, `not like` | `['like', 'linkText', 'download']` |

`like` checks for a case-insensitive substring and doesn’t use SQL wildcard syntax. Date comparisons accept date objects, for example `['>=', 'publishDate', now]` when the selected type has a Date field named `publishDate`. These filters operate on this field’s links in memory, rather than searching links across entries.

## Methods

::: reference
### `getLink()`

**Arguments:** `$attributes` (`array`, optional), extra attributes for the anchor.

**Returns:** `Twig\Markup|null`

Returns the first selected link as an `<a>` element, using Hyper’s saved label and attributes. This is null when nothing is selected or the first link has no destination. Attributes you pass override the saved ones.
:::

::: reference
### `first()`

**Returns:** `verbb\hyper\base\LinkInterface|null`

Returns the first selected [Link](/reference/link), or null when nothing is selected. Use it to read custom fields and call type-specific methods.

```twig
{% set link = entry.myLinkField.first() %}

{% if link %}
    {{ link.getLink() }}
{% endif %}
```
:::

::: reference
### `all()`

**Returns:** `array`

Returns the selected [Link](/reference/link) objects as a zero-indexed array, with filtering, ordering, offset and limit applied.
:::

::: reference
### `empty()`

**Arguments:** `$empty` (`bool|null`, defaults to `false`).

**Returns:** `self`

Selects links by whether they have a destination, returning a new selection. Pass `false` for links with a URL (the default), `true` for links without one, or `null` for either. The other selection options stay the same.
:::

::: reference
### `where()`

**Arguments:** `$condition` (`array`), a hash or operator condition.

**Returns:** `self`

Filters the links by the given conditions, returning a new selection. The conditions replace any earlier ones, while the empty scope, ordering, offset and limit stay the same.
:::

::: reference
### `andWhere()`

**Arguments:** `$condition` (`array`), a hash or operator condition.

**Returns:** `self`

Adds a condition that links must also match, returning a new selection. With no earlier conditions, the given condition is used on its own.
:::

::: reference
### `orWhere()`

**Arguments:** `$condition` (`array`), a hash or operator condition.

**Returns:** `self`

Adds a condition that links can match instead, returning a new selection. With no earlier conditions, the given condition is used on its own.
:::

::: reference
### `orderBy()`

**Arguments:** `$columns` (`array|string`).

**Returns:** `self`

Sets the order of the selected links, returning a new selection. Use a string such as `'priority DESC, linkText ASC'`, or a PHP array mapping attributes to `SORT_ASC` or `SORT_DESC`. An empty array restores the saved order.
:::

::: reference
### `limit()`

**Arguments:** `$limit` (`int|null`), a non-negative number.

**Returns:** `self`

Limits how many links are selected, after filtering and sorting, and returns a new selection. `limit(0)` selects nothing and `limit(null)` removes the limit.
:::

::: reference
### `offset()`

**Arguments:** `$offset` (`int`), a non-negative number.

**Returns:** `self`

Skips a number of matching links, after filtering and sorting, and returns a new selection. The default offset is `0`.
:::

::: reference
### `count()`

**Returns:** `int`

Returns the number of selected links, including the effect of offset and limit. Twig’s `length` filter uses the same count.
:::

::: reference
### `exists()`

**Returns:** `bool`

Returns whether any links are selected.
:::

::: reference
### `isEmpty()`

**Returns:** `bool`

Returns whether no links are selected. Twig’s `empty` test uses the same check. On an individual Link, `isEmpty()` instead checks for a destination.
:::

::: reference
### `getUrl()`

**Returns:** `string|null`

Returns the first selected link’s URL, or null when nothing is selected. Converting the collection to a string returns the same URL, or an empty string.
:::

::: reference
### `getText()`

**Arguments:** `$defaultText` (`string|null`, optional).

**Returns:** `string|null`

Returns the first selected link’s label, or null when nothing is selected. Pass a default to use when the link has no label.
:::

::: reference
### `getTarget()`

**Returns:** `string|null`

Returns the first selected link’s `target` attribute, or null when nothing is selected or it doesn’t open in a new window.
:::

::: reference
### `getTitle()`

**Returns:** `string|null`

Returns the first selected link’s `title` attribute, or null when nothing is selected.
:::

::: reference
### `getLinkText()`

**Returns:** `string|null`

Returns the first selected link’s Link Text with its fallbacks, or null when nothing is selected.
:::

::: reference
### `getCustomLinkText()`

**Returns:** `string|null`

Returns the Link Text an editor entered on the first selected link, with no fallbacks, or null when it’s blank or nothing is selected.
:::

::: reference
### `getLinkUrl()`

**Returns:** `string|null`

Returns the first selected link’s type-specific destination, before the URL prefix and suffix, or null when nothing is selected.
:::

The first-link getters also have matching properties, such as `url` and `text`. Read custom fields and call type-specific methods on an actual Link from `first()` or a loop. [Link](/reference/link) explains what each value means.

## Editing Stored Content

Selection methods affect reads. They never remove links from the editor or from saved content. Craft’s field-level storage emptiness check remains based on authored content, so first saves and propagation retain rows that have no destination. The following methods work on the complete stored collection, independently of its selection.

::: reference
### `getLinks()`

**Returns:** `array`

Returns every stored Link object, including incomplete links and unavailable types, whatever the selection. Use it in editing and migration code, and `all()` for template selections.
:::

::: reference
### `serializeValues()`

**Returns:** `array`

Returns every stored link in the format Hyper saves, for saving or propagation. Applying a selection before serialising doesn’t delete the excluded content.
:::

::: reference
### `setLinks()`

**Arguments:** `$value` (`array`), the replacement links as Link objects or link data.

**Returns:** `void`

Replaces the stored links in this collection.
:::

::: reference
### `withLinks()`

**Arguments:** `$links` (`array`), the replacement links as Link objects or link data.

**Returns:** `self`

Creates a new collection containing the replacement links and leaves this one unchanged. To save only a selection on purpose, pass that selection’s `all()` result to `withLinks()` before assigning it to the owner.
:::

Array reads use zero-based selected positions. Replacing or unsetting a position changes that selected record, while appending adds a stored link, which appears in the selection only if it matches. Selection objects share their Link objects, so editing a selected Link changes that object. Explicitly cloning a collection clones its Link objects as well.

Service code can type-hint `LinkCollectionInterface` for selection and stored-content methods. [Creating Links Programmatically](/guides/developers/creating-links-programmatically) shows how to construct links and save the collection on an owner.
