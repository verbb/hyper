# Performance

Hyper 3 resolves element links through the `hyper_links` relations table and **batch hydration** — not the retired `hyper_element_cache`. Use this page as the developer checklist; deep path syntax lives in the feature docs.

## Forward loading (owners → linked elements)

| Need | Approach |
| --- | --- |
| URL / label only | Always-on priming on front-end owner populate — often no extra work |
| Linked element object | `link.element` / `getElement()` after owners are queried |
| Fields on linked elements | Explicit `with()` on the **owner** query |

```php
use craft\elements\Entry;

$entries = Entry::find()
    ->section('nav')
    ->with(['myLinkField.linkedElements.thumbnail'])
    ->all();
```

```twig
{% for item in craft.entries()
    .section('nav')
    .with(['myLinkField.linkedElements.thumbnail'])
    .all() %}
    {{ item.myLinkField.element.thumbnail.one().url }}
{% endfor %}
```

Put the Hyper field handle first, then `.linkedElements`, then nested field handles. Matrix/Neo nesting is supported; Vizy is not a durable relation owner for batch priming in 3.0.

Full path table and priming vs `with()` comparison: [Eager Loading](/feature-tour/eager-loading).

## Reverse loading (linked element → owners)

Find entries (or other owners) that point **at** a given element:

```twig
{% set linkingPages = craft.hyper.getRelatedElements({
    relatedTo: {
        targetElement: entry,
        field: 'relatedContent',
    },
    elementType: 'craft\\elements\\Entry',
    site: currentSite.handle,
}).all() %}
```

PHP: `Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([…])`.

Relation rows sync when owners are saved. After upgrading from Hyper 2, resave critical entries if reverse lookups look incomplete.

Full parameters and multisite notes: [Reverse Relations](/feature-tour/reverse-relations).

## What not to do

- Do not rely on Craft native `relatedTo` against Hyper fields — Hyper is not a relation field.
- Do not expect **Utilities → Clear Caches** Hyper cache entries — element cache is removed in 3.0.
- Do not pass Hyper `with()` paths expecting Craft’s field eager-loader; Hyper intercepts them during query preparation.

## Upgrade context

Storage, cache removal, and GraphQL naming changes are summarized in [Upgrading from v2](/get-started/upgrading-from-v2).
