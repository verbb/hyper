# GraphQL Fields

Hyper fields return lists of `HyperLinkInterface` values, including fields configured for a single link. Start with the complete query and schema setup in [GraphQL](/developers/graphql).

## Link Interface

The [HyperLinkInterface field reference](/developers/graphql#the-hyperlinkinterface-interface) lists the shared fields and their GraphQL types alongside the working query examples.

## Concrete Types and Custom Fields

A concrete type is named `{fieldHandle}_{PascalCaseLinkTypeHandle}_LinkType`. For example, field `resourceLinks` and link type handle `custom-url` produce `resourceLinks_CustomUrl_LinkType`. Use the original handle from **Settings → Fields**, even when a layout gives that field an alias. The query uses the layout alias, while its concrete type keeps the original field handle so different fields remain distinct. The control-panel label does not determine the name.

Use a fragment on that concrete type to request its custom layout fields with their GraphQL types. Alternatively, `fields` returns permitted custom field values as a JSON string keyed by handle. Both forms respect the active schema’s field restrictions.

Names listed in `HyperLinkInterface`, such as `text` and `url`, always return Hyper’s link values. If a custom field’s layout handle matches one of these names, read its value from `fields`, or give it a unique layout handle to query it in a typed fragment.

If a saved link's type is unavailable, it returns the concrete type `HyperMissingLink`. Include `__typename` in your query to identify these items. Its saved label remains available through `linkText`, while `url`, `text` and `link` return null and `fields` returns the JSON string `[]`. Hyper retains the original content so restoring the type can make the link usable again.

`element` also respects the schema’s permissions for the destination. A stored link does not grant access to a restricted entry, site or user group. It can return null even when the owner entry is readable.

`linkValue` is JSON encoded, including scalar values. Embed links expose `html`, `iframeSrc`, `embedImage` and `providerName` so clients do not need to parse metadata for these common values. Those fields return null when the corresponding embed value is unavailable.
