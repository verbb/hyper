# Reverse Relations

Use `craft.hyper.getRelatedElements()` to find entries (or other elements) that link **to** a particular element through a Hyper field — for example, a “Used by” list on an entry edit screen or a “Pages linking here” module.

Hyper resolves reverse lookups through the `hyper_links` table. Relation rows sync when owner elements are saved.

```twig
{% set linkingPages = craft.hyper.getRelatedElements({
    relatedTo: {
        targetElement: entry,
        field: 'relatedContent',
    },
    elementType: 'craft\\elements\\Entry',
    site: currentSite.handle,
}).all() %}

<ul>
    {% for page in linkingPages %}
        <li><a href="{{ page.url }}">{{ page.title }}</a></li>
    {% endfor %}
</ul>
```

## Parameters

| Param | Required | Description |
| --- | --- | --- |
| `relatedTo.targetElement` | Yes | The element being linked **to**. |
| `relatedTo.field` | Yes | Handle of the Hyper field on owner elements. |
| `elementType` | No | Owner element class. Default: `craft\elements\Entry`. |
| `site` | No | Site handle for owners. Default: current site. |
| `criteria` | No | Additional ElementQuery criteria merged onto the result (e.g. `section`, `id`). |

```twig
{% set linkingPages = craft.hyper.getRelatedElements({
    relatedTo: {
        targetElement: entry,
        field: 'relatedContent',
    },
    criteria: {
        section: 'pages',
        id: ['not', 123],
    },
}).all() %}
```

Returns an `ElementQuery` — chain `all()`, `one()`, `count()`, etc.

## PHP

```php
use craft\elements\Entry;
use verbb\hyper\Hyper;

$query = Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
    'relatedTo' => [
        'field' => 'relatedContent',
        'targetElement' => $targetEntry,
    ],
    'elementType' => Entry::class,
    'site' => 'default',
    'criteria' => [
        'section' => 'pages',
    ],
]);
```

## Multisite

Reverse lookups match `targetId` and `targetSiteId` from the `hyper_links` row. Pass the target element from the site context you care about; owner results respect the `site` param.

## What this is not

- **`link.element` / `getElement()`** — the forward target of a single link value on one owner.
- **Craft `relatedTo` on Hyper fields** — not supported; Hyper links are not native relation fields.

For forward element loading and `with()` paths, see [Eager Loading](/feature-tour/eager-loading). Developer checklist: [Performance](/guides/developers/performance).
