# Loading Links

Hyper resolves element links through its relation index and element queries. Use this reference for explicit loading paths and reverse-query parameters after reading [Eager Loading](/feature-tour/eager-loading) or [Reverse Relations](/feature-tour/reverse-relations).

## Loading Paths

Pass these paths to `with()` on the query for the elements containing the Hyper field. Substitute the field handles in braces; the braces themselves are not part of the path.

| Path | Result |
| --- | --- |
| `{hyperField}.linkedElements` | Load linked target elements together. |
| `{hyperField}.linkedElements.{fieldHandle}` | Also eager-load the named field on each target. |
| `{hyperField}.linkedElements.{fieldHandle}.{nested}` | Continue with a nested Craft eager-loading path on the targets. |
| `{matrixField}.{hyperField}.linkedElements.{fieldHandle}` | Load fields on targets selected inside Matrix. |
| `{neoField}.{hyperField}.linkedElements.{fieldHandle}` | Load fields on targets selected inside Neo. |

In a module or console action, the equivalent PHP query is:

```php
use craft\elements\Entry;

$articles = Entry::find()
    ->section('news')
    ->with(['relatedArticle.linkedElements.thumbnail'])
    ->all();
```

This assumes a News section with a Hyper field `relatedArticle` linking to entries with a `thumbnail` field. Hyper handles these paths during query preparation. On normal frontend site requests it also registers queried owners for automatic target batching. Explicit paths add eager loading for the requested fields on those targets.

Vizy blocks are embedded content rather than independently persisted element owners. Hyper links inside them can resolve targets individually, but nested Vizy paths and automatic target batching are not supported.

## Reverse Lookup Parameters

`craft.hyper.getRelatedElements(params)` returns an element query for owners that link to a target through a Hyper field. It returns `null` when required arguments are missing or the field cannot be resolved as a Hyper field. With valid arguments but no matching links, it returns an empty query.

| Parameter | Required | Description |
| --- | --- | --- |
| `relatedTo.targetElement` | Yes | The target element, including its site context. |
| `relatedTo.field` | Yes | Handle of the Hyper field on the owners. |
| `elementType` | No | Owner element class. Defaults to `craft\elements\Entry`. |
| `site` | No | Site for the owners. Defaults to the current site. |
| `criteria` | No | Additional query criteria, such as `section` or an ID exclusion. |

Chain `all()`, `one()` or `count()` on the result. The relation index records owner and target sites separately; selecting the owner site does not change the target element’s site.

For a PHP module or console action, supply an existing `$targetEntry` and use:

```php
use craft\elements\Entry;
use verbb\hyper\Hyper;

$query = Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
    'relatedTo' => [
        'field' => 'relatedContent',
        'targetElement' => $targetEntry,
    ],
    'elementType' => Entry::class,
    'criteria' => ['section' => 'news'],
]);
$articles = $query?->all() ?? [];
```

Relation rows are synchronised when eligible owners are saved. The index does not give embedded values an independent owner identity. Craft’s native `relatedTo` does not query Hyper links.
