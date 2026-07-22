# Eager Loading

Hyper resolves element link targets through the `hyper_links` relations table and **batch element hydration**.

For how element links are stored, see [Element Links](/feature-tour/element-links). For reverse lookups, see [Reverse Relations](/feature-tour/reverse-relations). Developer checklist: [Performance](/guides/developers/performance).

## What to reach for

The right approach depends entirely on how much of the linked element your template touches, and the cheapest option that still does the job is almost always the correct one.

If you’re only rendering the link itself — its destination and label — stick with `link.url` and `link.text`. Both are read straight from the stored link content, so they never load the target element and never add a query. This covers the majority of navigation and call-to-action templates.

The moment you need the linked element as an object — to read its status, check which section it belongs to, or call a method on it — reach for `link.element` (or `getElement()`). On front-end requests Hyper batch-loads these targets for you, which is covered in [Always-on priming](#always-on-priming) below, so a listing that touches `link.element` doesn’t turn into one query per row.

Going one level deeper — reading custom fields _on_ the linked element, like a thumbnail asset or a nested relation — is where you should add an explicit eager-loading path to the **owner** query, for example `with(['myLinkField.linkedElements.thumbnail'])`. That’s detailed in [Eager loading with `with()`](#eager-loading-with-with).

Finally, if the question is reversed — “which owner elements link _to_ this entry?” — that’s not a read off the field at all, but a reverse lookup via `craft.hyper.getRelatedElements()`. See [Reverse Relations](/feature-tour/reverse-relations).

## Always-on priming

On front-end site requests, Hyper registers owner elements during `ElementQuery` populate and batch-primes linked targets via `LinkRelations::primePendingOwners()`. Nav-style templates that only output `link.url` / `link.text` benefit without an explicit `with()` path.

Implicit batching prevents N+1 queries on typical navigation templates.

## Eager loading with `with()`

When templates access `link.element` or custom fields on linked elements, add a Craft `with()` path on the **owner** element query. Put the Hyper field handle first, then `.linkedElements`, then nested field handles:

```php
Entry::find()
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

### Supported paths

The path always starts with the Hyper field handle followed by `.linkedElements`. From there you can go deeper depending on what you need:

- `{hyperField}.linkedElements` — batch-loads the linked target elements themselves.
- `{hyperField}.linkedElements.{fieldHandle}` — also eager-loads `{fieldHandle}` on each target (e.g. a thumbnail asset).
- `{hyperField}.linkedElements.{fieldHandle}.{nested}` — continues with normal Craft `with` paths on the target batch query, so you can drill into fields on those targets.

Hyper fields nested inside other fields work too — just prefix the parent handle:

- `{matrixField}.{hyperField}.linkedElements.{fieldHandle}` — Hyper fields inside Matrix blocks.
- `{neoField}.{hyperField}.linkedElements.{fieldHandle}` — Hyper fields inside Neo blocks (resolved with a generic nested walk).

:::warning Vizy nesting
Hyper fields inside **Vizy** blocks are not Matrix-equivalent for relation ownership. Vizy blocks are ephemeral content nodes (no durable element ID), so `hyper_links` rows and always-on priming target Matrix/Neo/top-level owners only. Element resolution inside Vizy still works via per-link `getElement()`; batch priming and nested `with()` through Vizy are not supported in 3.0.
:::

Hyper intercepts these paths during query preparation instead of passing them to Craft’s native field eager loader (which Craft 5 does not invoke for Hyper fields).

See also [Rendering Links — Element Links](/feature-tour/rendering-links#element-links).

## Priming vs explicit `with()`

It’s easy to assume always-on priming makes `with()` unnecessary, but they solve different halves of the problem. The distinction that matters is whether you also need fields _on_ the linked targets.

Always-on priming batch-loads the target elements themselves when the owner query populates. It’s what keeps a nav template that outputs `link.url` and `link.text`, or reads simple element properties, from firing a query per link — and it happens automatically, with nothing to add. What it deliberately doesn’t do is load any nested fields on those targets.

That’s the gap an explicit `with()` path fills. Writing `with(['field.linkedElements'])` performs the same target batch-load, but on demand — useful when priming doesn’t apply, such as outside a normal front-end request. Extending it to `with(['field.linkedElements.thumbnail'])` goes further, loading the targets _and_ eager-loading the named field on each one in the same batch.

So the rule of thumb is simple: rely on priming for plain links, but add an explicit `with()` path the moment your templates read custom fields on the linked entries, assets, or categories — priming alone will never cover those.

## GraphQL

GraphQL resolves element links through the same batch hydration path as element queries. Use stable link type handles in schema names (see [GraphQL](/developers/graphql)).
