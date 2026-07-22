# Upgrading from v2
Hyper 3 is a major release with storage, performance, and API changes. This page is the canonical upgrade reference for sites on Hyper 2.x.

## Breaking Changes

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
    "uid": "e5f2c0b1-…"
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
    "uid": "a17b9d34-…"
}
```
:::

### Field UI &amp; the Empty State

The CP field no longer pre-renders a blank link for **single-link** fields. In v2 a single-link field always showed one editable link block, even before you'd entered anything. In v3 an empty field shows a clear **empty state** with an “Add link” action — you add the link explicitly, the same way multi-link fields already worked.

This is a CP authoring change only. Template access is unchanged: the field value is still a `LinkCollection`, single-link fields still delegate to the first link (`entry.linkField.url`, `.getLink()`, etc.), and empty checks such as `{% if not entry.linkField.isEmpty() %}` work exactly as they did in v2.

### GraphQL Type Names

Concrete GraphQL link types are named `{fieldHandle}_{LinkTypeHandle}_LinkType`. The pattern is unchanged, but the name now derives from the link type’s **handle** rather than its CP label. Built-in types use a short handle that matches their lowercased name (`url`, `entry`, …), so their generated type names are identical to v2 — a URL type is still `linkField_Url_LinkType`. Only *custom* link types you define with their own handle produce a different name (based on that handle), so update any inline fragments that targeted a label-derived name for a custom type:

::: code-group
```graphql [Hyper 2]
linkField {
    # Built-in URL type — unchanged in v3
    ... on linkField_Url_LinkType {
        linkText
    }
}
```

```graphql [Hyper 3]
linkField {
    # Built-in URL type — same name as v2
    ... on linkField_Url_LinkType {
        linkText
    }

    # A custom type with handle `promoUrl` casts to its handle
    ... on linkField_PromoUrl_LinkType {
        linkText
    }
}
```
:::

See [GraphQL](/developers/graphql) for the full schema reference.

Embed and custom layout values use the shared interface fields (`html`, `iframeSrc`, `embedImage`, `providerName`, `fields`) — no per-label type cast required for those bags.

## Behaviour Changes

These don’t require action to upgrade, but are worth knowing — several are fixes or new capabilities rather than things that will break.

### Element Cache Removed

Hyper no longer uses `hyper_element_cache` or `hyper_field_cache`. Element link targets are resolved via batch hydration and the `hyper_links` relations table.

- **Removed:** `ElementCache` service, element save/delete cache sync, **Utilities → Clear Caches** entry for Hyper
- **Added:** `hyper_links` table with a one-time backfill from legacy cache rows on upgrade
- **Fresh installs** never create the legacy tables

See [Element Links](/feature-tour/element-links) for read/write behaviour.

### Eager Loading with `with()`

Hyper now handles its own `with()` paths during element query preparation. In v2 these paths were effectively ignored, so this is a new capability rather than a change you need to react to. Include the Hyper field handle first:

```php
Entry::find()
    ->section('nav')
    ->with(['myLinkField.linkedElements.thumbnail'])
    ->all();
```

See [Eager Loading](/feature-tour/eager-loading) and the developer [Performance](/guides/developers/performance) checklist.

### Multisite Propagation

Multisite propagation is fixed in v3. Element links now localize during Craft propagation (`normalizeValue()` / `propagateValue()`), matching native Link/Entries field behaviour. Multi-link **structure** sync to sibling sites runs only when the Hyper field was edited on the saving site.

Per-site link text remains editable when the field uses “Translate for each site”. Sibling sites receive the same link **structure** when you edit the field on the source site; they do not overwrite site-specific text.

### Removed Twig Page Preload

Hyper no longer preloads element cache on `BEFORE_RENDER_PAGE_TEMPLATE`. Front-end requests rely on always-on batch priming during owner element query populate instead. Template output is unchanged; this is an internal performance change.

### Console Migration Commands

Migration commands are now **one action per source** with a `--step` flag, rather than a separate `-field` / `-content` command per source:

```shell
# v3 — one command per source, choose the step
./craft hyper/migrate/typed-link --step=field
./craft hyper/migrate/typed-link --step=content
./craft hyper/migrate/typed-link              # omit --step to run both (default: all)
```

Sources are `typed-link`, `linkit`, `link`, `craft-link`, and `oembed`. See [Migrations & Upgrades](/guides/migrations-upgrades) for the full option list (`--dry-run`, `--sync-relations`, `--create-backup`).

The old per-step commands (`hyper/migrate/typed-link-field`, `hyper/migrate/typed-link-content`, and their `linkit` / `link` equivalents) still work, but are **deprecated** — they now raise a Craft deprecation warning (see **Utilities → Deprecation Warnings**). Update any deploy scripts or run-once hooks to the `--step` form.

### Other behaviour changes

A few smaller behaviours changed to match the new storage model:

- `craft.hyper.getRelatedElements()` now resolves purely from `hyper_links` rather than the removed element cache.
- `isEmpty()` is smarter — instead of only checking the URL in some cases, it respects link text, classes, and custom field values.
- `target` / `newWindow` now fall back to the field-level default when they aren’t set per link, rather than sometimes ignoring it.
- Link type definitions are stored as `LinkTypeDefinition` data in the field JSON, with the registry `Link` classes acting as prototypes — in v2 these were registry objects held in settings.
- Deleting a site now scrubs stale site UIDs from settings, link type configs, and stored content, where v2 could leave them behind.
