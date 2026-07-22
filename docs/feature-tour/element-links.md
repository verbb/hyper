# Element Links

Entry, Category, Asset, and other **element link types** point at a Craft element and use its title and URL for the link.

Hyper stores the selected element reference in the field’s content JSON. When the owner element is saved, Hyper also syncs a row in the `hyper_links` relations table (`targetId`, `targetSiteId`, and field id). Those relation rows power [Reverse Relations](/feature-tour/reverse-relations) lookups and let Hyper batch-load linked elements when you read the field.

For Twig output patterns, see [Rendering Links — Element Links](/feature-tour/rendering-links#element-links).

## URL & Text

If all you need is the link’s destination or its label, read the values straight off the field:

```twig
{{ myLinkField.url }}
{{ myLinkField.text }}
```

These are resolved from the stored link content, so they’re fast and never require loading the linked element. Prefer this whenever you’re rendering a plain link.

## Linked Element

When you need the target element — for example to read its status, check its section, or call a method on it — access the element:

```twig
{% set entry = myLinkField.element %}
{# or #}
{% set entry = myLinkField.getElement() %}
```

Hyper batch-primes linked targets when owner element queries populate on front-end requests, so listings that touch `element` generally avoid a query per link. See [Eager Loading](/feature-tour/eager-loading) for how priming works and when it applies.

## Custom Fields

If you’ll be reading custom fields off the linked element (like a thumbnail or a nested relation), eager-load them from the owner query so they’re fetched together rather than one element at a time:

```twig
{% set entries = craft.entries()
    .with(['myLinkField.linkedElements.thumbnail'])
    .all() %}
```

This is the key step for keeping large navigations and card grids efficient. See [Eager Loading](/feature-tour/eager-loading) for the full syntax and more examples.
