# LinkCollection

A Hyper field returns a `LinkCollection` in both single-link and multiple-link mode. Use it to render the first link, loop over links, or select links by their type and custom fields. **Enable Multiple Links** controls authoring; the template API stays the same.

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

A link is empty when it cannot resolve a non-blank URL. Labels, classes and custom fields alone do not give it a destination. Links to unavailable entries and Passive labels are empty under this definition. Hyper resolves the destination locally; it does not request an external website to check whether it exists.

Iteration, array access, `first()`, `count()`, `exists()` and rendering shortcuts all use the current selection. Loop metadata such as `loop.length` and `loop.last` describes that selection. With no matches, `first()` returns `null`, `getLink()` outputs nothing and a loop’s `{% else %}` branch runs.

## Filter Links

Suppose you have a custom URL type with the handle `externalResource`, with a Lightswitch field called `featured` in its layout. Display only featured resources:

```twig
{% for link in entry.myLinkField.where({ handle: 'externalResource', featured: true }) %}
    {{ link.getLink() }}
{% endfor %}
```

`handle` identifies a configured link type, so two custom URL types can be selected independently. The native `type` attribute contains the PHP class name; for example, `{ type: 'verbb\\hyper\\links\\Url' }` selects URL links across their configured handles.

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

Filters return a new selection and leave the original collection unchanged. `where()` replaces the ordinary conditions; `andWhere()` and `orWhere()` combine them. The empty-link scope is separate, so replacing conditions or adding an OR branch does not include empty links accidentally. Equal sort values keep their saved order, and overlapping OR branches return each occurrence once without collapsing repeated links.

You can iterate a selection directly. Call `all()` when another API needs an array; it returns the same links. No `query()` call is required.

### Empty Links

Use `empty()` to control which destinations are included:

| Selection | Result |
| --- | --- |
| `empty(false)` | Links with a resolved URL; the default. |
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

`like` checks for a case-insensitive substring; it does not use SQL wildcard syntax. Date comparisons accept date objects, for example `['>=', 'publishDate', now]` when the selected type has a Date field named `publishDate`. These filters operate on this field’s links in memory, rather than searching links across entries.

## Methods

::: reference
### `getLink(array $attributes = [])`

**Returns:** `Twig\Markup|null`

Render the first selected link with Hyper’s saved label and attributes. Return `null` if there is no selected link or its destination is empty. Template attributes override saved rendering attributes.
:::

::: reference
### `first()`

**Returns:** `verbb\hyper\base\LinkInterface|null`

Return the first selected link object, or `null`. Use it for custom fields and type-specific methods:

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

Return the selected Link objects as a zero-indexed array, with filtering, ordering, offset and limit applied.
:::

::: reference
### `empty(?bool $empty = false)`

**Returns:** `self`

Return a new selection containing non-empty links (`false`), empty links (`true`), or either (`null`). Preserve the other selection options.
:::

::: reference
### `where(array $condition)`

**Returns:** `self`

Return a new selection with the supplied conditions replacing previous conditions. Preserve the empty scope, ordering, offset and limit.
:::

::: reference
### `andWhere(array $condition)` / `orWhere(array $condition)`

**Returns:** `self`

Return a new selection combining existing conditions with the supplied condition using AND or OR. With no existing conditions, use the supplied condition.
:::

::: reference
### `orderBy(array|string $columns)`

**Returns:** `self`

Replace the selection’s ordering. Use a string such as `'priority DESC, linkText ASC'`, or a PHP array mapping attributes to `SORT_ASC` or `SORT_DESC`. An empty array restores saved order.
:::

::: reference
### `limit(?int $limit)` / `offset(int $offset)`

**Returns:** `self`

Limit the result size or skip a number of matches after filtering and sorting. Values must be non-negative. `limit(0)` selects nothing; `limit(null)` removes the limit. The default offset is `0`.
:::

::: reference
### `count()` / `exists()` / `isEmpty()`

**Returns:** `int` / `bool` / `bool`

Count selected links, check whether any are selected, or check whether none are selected. These methods include the effects of offset and limit. Twig’s `length` and `empty` tests use the same selection.
:::

::: reference
### First-Link Getters

**Returns:** `string|null`

`getUrl()`, `getText(?string $defaultText = null)`, `getTarget()`, `getTitle()`, `getLinkText()`, `getCustomLinkText()` and `getLinkUrl()` read the first selected link and return `null` when there is none. Their corresponding properties, such as `url` and `text`, are available too. String conversion returns its resolved URL or an empty string.

These are explicit collection conveniences. Read custom fields and call type-specific methods on an actual Link from `first()` or iteration. See [Link](/reference/link) for each value’s meaning.
:::

## Editing Stored Content

Selection methods affect reads. They never remove links from the editor or from saved content. Craft’s field-level storage emptiness check remains based on authored content, so first saves and propagation retain rows that have no destination. The following methods work on the complete stored collection, independently of its selection.

::: reference
### `getLinks()`

**Returns:** `array`

Return every stored Link object, including incomplete links and unavailable types. Use this in editing and migration code. Use `all()` for template selections.
:::

::: reference
### `serializeValues()`

**Returns:** `array`

Serialise every stored link for saving or propagation. Applying a selection before serialising does not delete the excluded content.
:::

::: reference
### `setLinks(array $links)` / `withLinks(array $links)`

**Returns:** `void` / `self`

Replace the stored links in place, or return a new collection containing the replacement links. To intentionally save only a selection, pass that selection’s `all()` result to `withLinks()` before assigning it to the owner.
:::

Array reads use zero-based selected positions. Replacing or unsetting a position changes that selected record; appending adds a stored link, which appears in the selection only if it matches. Selection objects share their Link objects, so editing a selected Link changes that object. Explicitly cloning a collection clones its Link objects as well.

Service code can type-hint `LinkCollectionInterface` for selection and stored-content methods. [Creating Links Programmatically](/guides/developers/creating-links-programmatically) shows how to construct links and save the collection on an owner.
