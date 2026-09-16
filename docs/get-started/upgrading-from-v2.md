# Upgrading from v2
Hyper 3 is a major release with storage, performance, and API changes. Use this page to identify the template, configuration and integration changes needed when moving from Hyper 2.

## Breaking Changes

### Literal Link Destinations

Hyper 3 treats stored link destinations as literal text. If your links use environment-variable references or Craft aliases, replace them with public URLs or Site links before upgrading. For destinations selected by your application configuration, resolve the public URL in trusted template or module code. Check the rendered links on each site after updating them.

This also applies to URL link type defaults and fixed values. Craft's configured site base URLs continue to work through Site links.

### Link Content Shape

Saved link content now identifies its link type with a stable **`linkTypeHandle`** only. In v2 each stored link carried both a `type` (the PHP class FQCN) and a duplicate `handle`; v3 drops the FQCN entirely. Hyper still dual-reads legacy v2 payloads (falling back to `handle`, then `type`) so existing content keeps working, but every new save writes the v3-only shape.

This only affects you if you read the raw field content JSON directly or depend on the `type` FQCN — normal Twig and PHP model access is unchanged.

A stored URL link — `type` is gone in v3 and `handle` becomes `linkTypeHandle`:

::: code-group
```json [Hyper 2]
{
    "type": "verbb\\hyper\\links\\Url",
    "handle": "default-verbb-hyper-links-url",
    "linkValue": "https://verbb.io",
    "linkText": "Verbb",
    "newWindow": false
}
```

```json [Hyper 3]
{
    "linkTypeHandle": "url",
    "linkValue": "https://verbb.io",
    "linkText": "Verbb",
    "newWindow": false,
    "uid": "e5f2c0b1-2752-4f47-bb9c-3ad65da84f7c"
}
```
:::

Element links change the same way — only the type identifier differs:

::: code-group
```json [Hyper 2]
{
    "type": "verbb\\hyper\\links\\Entry",
    "handle": "default-verbb-hyper-links-entry",
    "linkValue": 42
}
```

```json [Hyper 3]
{
    "linkTypeHandle": "entry",
    "linkValue": 42,
    "uid": "a17b9d34-7c4c-4d71-9af4-562f3be05297"
}
```
:::

For raw content migrations, the [Content API](/developers/managing-embedded-content) captures link type aliases so it can locate fields in legacy link rows. Rows saved before link UIDs were introduced are identified by their stored index, with `linkUid` set to null. Raw field replacements preserve that representation without generating a UID.

### GraphQL Type Names

Concrete GraphQL link types are named `{fieldHandle}_{PascalCaseLinkTypeHandle}_LinkType`. Hyper 2 used the label for custom types; Hyper 3 uses the configured handle. Built-in types keep their expected names, such as `linkField_Url_LinkType`, when the field uses its original handle.

For a custom type labelled **Promotion** with the handle `promoUrl`, change the fragment from `Promotion` to `PromoUrl`. These partial selections belong inside the same entry-type fragment in your existing query:

::: code-group
```graphql [Hyper 2]
linkField {
    ... on linkField_Promotion_LinkType {
        linkText
    }
}
```

```graphql [Hyper 3]
linkField {
    ... on linkField_PromoUrl_LinkType {
        linkText
    }
}
```
:::

Hyper 3 uses the original field handle from **Settings → Fields** in the concrete type name. A field layout's alias still identifies the query field, but does not rename its type. If a field named `resourceLinks` is aliased as `apiLinks`, replace any `apiLinks_Url_LinkType` fragment with `resourceLinks_Url_LinkType`. This also applies when updating an earlier Hyper 3 beta. The corresponding partial selection is:

```graphql
apiLinks {
    ... on resourceLinks_Url_LinkType {
        linkText
    }
}
```

Inspect the active schema in GraphiQL and rerun each affected query. Custom type fragments should resolve and return the same intended fields. See [GraphQL](/developers/graphql) for a complete query and [HyperLinkInterface](/developers/graphql#the-hyperlinkinterface-interface) for the interface contract.

### Removed Element Cache API

Custom integrations that call `getElementCache()` or use the `ElementCache` service must change. Hyper 3 removes that service and the `hyper_element_cache` and `hyper_field_cache` tables. There is no replacement cache-clear call: use supported link reads, explicit loading paths or reverse queries according to the task.

| Hyper 2 Integration | Hyper 3 Approach |
| --- | --- |
| `Hyper::$plugin->getElementCache()->preloadCache()` | Remove the manual preload. Normal frontend queries use automatic target batching. |
| `Hyper::$plugin->getElementCache()->clearCache()` | Remove the call. Hyper does not maintain those element-cache tables. |
| Direct reads from `hyper_element_cache` | Use Link objects for destinations or `craft.hyper.getRelatedElements()` for incoming links. |

Search custom modules, plugins and deployment scripts for the removed service and table names. Run the affected frontend and console paths after replacing those calls. [Loading Links](/reference/loading-links) documents the supported loading API. Ordinary templates using Link objects do not need to manipulate an index or cache table.

### Link Output and URI Policy

Hyper 3 escapes ordinary link-label strings in `getLink()`. If your templates used HTML strings as labels, use template-authored Twig markup instead. Keep editor content escaped. [Rendering Links](/feature-tour/rendering-links#include-markup-in-the-label) shows a capture-based example.

The shared URL policy permits `http`, `https`, `mailto`, `tel` and `sms`, plus relative addresses and fragments. For application schemes your site uses, add them to `allowedUriSchemes` in `config/hyper.php`, then check the affected links on the frontend. Custom links also follow this policy. Executable schemes and event-handler attributes are blocked.

## Behaviour Changes

The following changes affect editing and loading behaviour. Review the parts your site uses; the relation rebuild runs automatically during the upgrade.

### Relation Index Rebuild

The upgrade rebuilds `hyper_links` from saved field content without resaving that content. Missing or stale legacy caches do not affect the result, and repeated links retain their order and separate occurrences.

Links whose targets have been permanently deleted retain their stored data but do not produce relation rows. Drafts, revisions and trashed owners retain their content and are excluded from the relation index. Existing beta installations also receive the rebuild when updating. Fresh installs do not create the legacy cache tables, and Hyper no longer appears under Utilities → Clear Caches.

### Field UI &Amp; the Empty State

The CP field no longer pre-renders a blank link for **single-link** fields. In v2 a single-link field always showed one editable link block, even before you'd entered anything. In v3 an empty field shows a clear **empty state** with an “Add link” action — you add the link explicitly, the same way multi-link fields already worked.

This is a CP authoring change only. Template access is unchanged: the field value is still a `LinkCollection`, single-link fields still delegate to the first link (`entry.linkField.url`, `.getLink()`, etc.), and `isEmpty()` remains available. Review its broader content checks described below.

### Eager Loading with `with()`

Hyper now handles its own `with()` paths during element query preparation. In v2 these paths were effectively ignored, so this is a new capability rather than a change you need to react to. Include the Hyper field handle first:

```php
use craft\elements\Entry;

Entry::find()
    ->section('nav')
    ->with(['myLinkField.linkedElements.thumbnail'])
    ->all();
```

See [Eager Loading](/feature-tour/eager-loading) and [Checking Link Performance](/guides/developers/checking-link-performance).

### Multisite Propagation

Multisite propagation is fixed in v3. Element links now localise during Craft propagation (`normalizeValue()` / `propagateValue()`), matching native Link/Entries field behaviour. Multi-link **structure** sync to sibling sites runs only when the Hyper field was edited on the saving site.

For multi-link fields on entries using **Translate for each site**, editing the field synchronises its structure to the other sites while retaining translated labels for matching links. See [Working with Multiple Sites](/feature-tour/working-with-multiple-sites) for the conditions and destination-site behaviour.

### Removed Twig Page Preload

Hyper no longer preloads element cache on `BEFORE_RENDER_PAGE_TEMPLATE`. Front-end requests rely on always-on batch priming during owner element query populate instead. Template output is unchanged; this is an internal performance change.

### Vizy Content Migrations

Hyper uses its Vizy 3 adapter when migrating content on installations running Vizy 3. With Vizy 4, it delegates document traversal to Vizy's Content API. See [Managing Embedded Content](/developers/managing-embedded-content) for capturing field locations and transforming nested values.

### Other Behaviour Changes

A few smaller behaviours changed to match the new storage model:

- `craft.hyper.getRelatedElements()` now resolves purely from `hyper_links` rather than the removed element cache.
- `isEmpty()` checks link text, classes and custom field values as well as the destination. A field can therefore be non-empty without a renderable URL. Use `link.url` when deciding whether to output an anchor.
- `target` / `newWindow` now fall back to the field-level default when they aren’t set per link, rather than sometimes ignoring it.
- Link type definitions are stored as `LinkTypeDefinition` data in the field JSON, with the registry `Link` classes acting as prototypes — in v2 these were registry objects held in settings.
- Deleting a site now scrubs stale site UIDs from settings, link type configs, and stored content, where v2 could leave them behind.

## Deprecated Changes

### Console Migration Commands

Migration commands are now **one action per source** with a `--step` flag, rather than a separate `-field` / `-content` command per source:

```shell
# v3 — one command per source, choose the step
./craft hyper/migrate/typed-link --step=field
./craft hyper/migrate/typed-link --step=content
./craft hyper/migrate/typed-link              # omit --step to run both (default: all)
```

Sources are `typed-link`, `linkit`, `link`, `craft-link` and `oembed`. Use the source-conversion guides in User Guides for their individual steps.

The old per-step commands (`hyper/migrate/typed-link-field`, `hyper/migrate/typed-link-content`, and their `linkit` / `link` equivalents) still work, but are **deprecated** — they now raise a Craft deprecation warning (see **Utilities → Deprecation Warnings**). Update any deploy scripts or run-once hooks to the `--step` form.
