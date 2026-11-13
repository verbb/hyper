# Interfaces & Types

A Hyper field returns a list of links, including a field configured for a single link. Every link implements the `HyperLinkInterface` interface, and each link type also has its own concrete type that adds the custom fields from its field layout. [Queries](/graphql/queries) shows how to request a Hyper field through Craft’s entry queries and set up the schema.

## Interfaces

### The `HyperLinkInterface` Interface

Select these fields directly on any Hyper field, without a link-type fragment. All of them are nullable.

```graphql
{
  entries(section: "news", limit: 1) {
    ... on newsPost_Entry {
      resourceLinks {
        type
        text
        url
        target
        newWindow
        isElement
        element {
          id
          title
        }
      }
    }
  }
}
```

```json
{
  "data": {
    "entries": [
      {
        "resourceLinks": [
          {
            "type": "verbb\\hyper\\links\\Entry",
            "text": "About Us",
            "url": "https://example.test/about",
            "target": null,
            "newWindow": false,
            "isElement": true,
            "element": {
              "id": "24",
              "title": "About Us"
            }
          }
        ]
      }
    ]
  }
}
```

::: reference
#### `ariaLabel`

**Type:** `String`

The `aria-label` attribute for the link.
:::

::: reference
#### `classes`

**Type:** `String`

The `class` attribute for the link.
:::

::: reference
#### `customAttributes`

**Type:** `ArrayType`

The custom attributes for the link, as a JSON value. `ArrayType` is a scalar, so it doesn’t take a nested selection.
:::

::: reference
#### `customLinkText`

**Type:** `String`

The text entered in the Link Text field, with no fallbacks. This is null when the field is blank, which lets your client apply its own default.
:::

::: reference
#### `element`

**Type:** `ElementInterface`

The linked element for element-based links, such as an entry or asset. Use a fragment on the element’s own type, such as `... on pages_Entry`, to select its fields. The active schema must allow access to that element.
:::

::: reference
#### `embedImage`

**Type:** `String`

The thumbnail or image URL from embed metadata, when present.
:::

::: reference
#### `fields`

**Type:** `String`

The link’s custom field values as a JSON object keyed by field handle. This respects the active schema’s field restrictions and doesn’t need a concrete type fragment.
:::

::: reference
#### `html`

**Type:** `String`

The embed HTML for an Embed link, or null for other link types.
:::

::: reference
#### `iframeSrc`

**Type:** `String`

The `src` of the first iframe in the embed HTML, when present.
:::

::: reference
#### `isElement`

**Type:** `Boolean`

Whether the link’s value is an element.
:::

::: reference
#### `isEmpty`

**Type:** `Boolean`

Whether the link has no resolved destination.
:::

::: reference
#### `link`

**Type:** `String`

The rendered HTML for an `<a>` element.
:::

::: reference
#### `linkText`

**Type:** `String`

The Link Text value, falling back to type-specific values such as an element’s title when Link Text is empty.
:::

::: reference
#### `linkUri`

**Type:** `String`

The URI of the linked element, for element-based links.
:::

::: reference
#### `linkUrl`

**Type:** `String`

The URL for the link.
:::

::: reference
#### `linkValue`

**Type:** `String`

The raw link data as a JSON string. Embed links return their full embed metadata.
:::

::: reference
#### `newWindow`

**Type:** `Boolean`

Whether the link should open in a new window.
:::

::: reference
#### `providerName`

**Type:** `String`

The oEmbed provider name for an Embed link, such as YouTube, when present.
:::

::: reference
#### `target`

**Type:** `String`

The `target` attribute for the link.
:::

::: reference
#### `text`

**Type:** `String`

The fully derived link label. Hyper uses the custom Link Text first, then type-specific fallbacks, then the field’s placeholder or the plugin default.
:::

::: reference
#### `title`

**Type:** `String`

The `title` attribute for the link.
:::

::: reference
#### `type`

**Type:** `String`

The link type.
:::

::: reference
#### `url`

**Type:** `String`

The URL for the link.
:::

::: reference
#### `urlPrefix`

**Type:** `String`

The URL prefix for the link.
:::

::: reference
#### `urlSuffix`

**Type:** `String`

The URL suffix for the link.
:::

## Types

### Concrete Link Types and Custom Fields

A concrete type is named `{fieldHandle}_{PascalCaseLinkTypeHandle}_LinkType`. For example, field `resourceLinks` and link type handle `custom-url` produce `resourceLinks_CustomUrl_LinkType`. Use the original handle from **Settings → Fields**, even when a layout gives that field an alias. The query uses the layout alias, while its concrete type keeps the original field handle so different fields remain distinct. The control-panel label does not determine the name.

Use a fragment on that concrete type to request its custom layout fields with their GraphQL types. Alternatively, `fields` returns permitted custom field values as a JSON string keyed by handle. Both forms respect the active schema’s field restrictions.

Names listed in `HyperLinkInterface`, such as `text` and `url`, always return Hyper’s link values. If a custom field’s layout handle matches one of these names, read its value from `fields`, or give it a unique layout handle to query it in a typed fragment.

Element links whose destinations are unavailable or outside the active schema are returned as null list items. Hyper does not resolve any interface or concrete-type fields for those items, so access to an owner entry does not grant access to its linked entry, category, asset, site, user or other element. Keep null items when list positions are significant to your client.

`linkValue` is JSON encoded, including scalar values. Embed links expose `html`, `iframeSrc`, `embedImage` and `providerName` so clients do not need to parse metadata for these common values. Those fields return null when the corresponding embed value is unavailable.

The custom-field example in [Queries](/graphql/queries#read-custom-fields) shows a fragment on a concrete type. Requesting `fields` instead returns a JSON string such as `{"summary":"Opening hours and directions"}`.

### The `HyperMissingLink` Type

`HyperMissingLink` is the concrete type for a saved link whose link type is no longer available. It implements `HyperLinkInterface`, so request `__typename` to recognise it. Its saved label remains available through `linkText`, while `url`, `text` and `link` return null and `fields` returns the JSON string `[]`. Hyper keeps the original content, so restoring the link type makes the link usable again.

```graphql
resourceLinks(empty: null) {
  __typename
  linkText
  url
}
```

```json
{
  "resourceLinks": [
    {
      "__typename": "HyperMissingLink",
      "linkText": "Old download",
      "url": null
    }
  ]
}
```

### The `ArrayType` Scalar

`ArrayType` is a JSON scalar used by `customAttributes`. Request the field name on its own, and your client receives the attributes as structured data.
