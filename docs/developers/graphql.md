# GraphQL

Use GraphQL when a separate frontend needs the destinations and labels stored in Hyper fields. Query the entry containing the field, then select the link properties your frontend needs.

## Prepare an Entry and Schema

This example assumes a News section with the handle `news`, an entry type with the handle `newsPost`, and a Hyper field with the handle `resourceLinks` attached to that entry type. A handle is the name used in code; replace these sample handles with your site’s values.

Save a published News entry with one URL link: `https://example.test/contact`, with Link Text set to `Contact us`.

In Craft’s **GraphQL → Schemas**, create or edit a schema that can read the News section and the relevant site. Enable access to the fields and destination content your query needs. Use **GraphQL → GraphiQL** with that schema to try the query. For a private schema used by an external client, create a token for it under **GraphQL → Tokens** and send it as a Bearer token to your Craft GraphQL endpoint. Keep private tokens on a trusted server.

Craft’s [GraphQL documentation](https://craftcms.com/docs/5.x/development/graphql.html) explains schema permissions, tokens and endpoint configuration.

## Query the Links

Paste this complete query into GraphiQL:

```graphql
query ResourceLinks {
    entries(section: "news", limit: 1) {
        ... on newsPost_Entry {
            title
            resourceLinks {
                url
                text
            }
        }
    }
}
```

The fragment `newsPost_Entry` identifies the entry type containing your custom field. If your entry type has a different handle, update the fragment as well as the field name. With one matching entry titled `Contact Information`, the response is:

```json
{
    "data": {
        "entries": [
            {
                "title": "Contact Information",
                "resourceLinks": [
                    {
                        "url": "https://example.test/contact",
                        "text": "Contact us"
                    }
                ]
            }
        ]
    }
}
```

Both `entries` and `resourceLinks` are lists. Hyper returns a list even when **Enable Multiple Links** is off. Add another link with multiple links enabled and rerun the query to see a second item in `resourceLinks`.

If Craft reports an unknown field or type, check the entry type handle, field placement and active schema permissions. If the result contains no entries, check publication status and the selected site.

## Read Custom Fields

Suppose the URL link type has a Plain Text field with the handle `summary`. Add it through [Link Fields](/feature-tour/custom-fields-inside-links), save a summary on your link, then request it using the URL link type’s concrete GraphQL type:

```graphql
query ResourceSummaries {
    entries(section: "news", limit: 1) {
        ... on newsPost_Entry {
            resourceLinks {
                url
                text
                ... on resourceLinks_Url_LinkType {
                    summary
                }
            }
        }
    }
}
```

For the built-in URL handle `url`, the type name ends in `Url_LinkType`. A custom link type uses its handle converted to PascalCase. Inspect the schema in GraphiQL to find the exact name.

The result includes `summary` on matching URL links. If your frontend needs a common representation across different layouts, request `fields` to receive permitted custom values as a JSON string instead. See [GraphQL Fields](/reference/graphql-fields) for that contract.

## Read Embed Metadata

For a field containing Embed links, request `html`, `iframeSrc`, `embedImage` and `providerName` alongside `url` and `text`. You can add these to the `resourceLinks` selection in the first query without a type fragment. They return null when the stored link has no corresponding metadata.

Check the stored embed in the editor before relying on its HTML or thumbnail. [Rendering Links](/feature-tour/rendering-links#embed-links) explains the difference between an anchor and embedded output; the [HyperLinkInterface reference](#the-hyperlinkinterface-interface) below lists the available response fields.

## The `HyperLinkInterface` Interface

Every Hyper link implements `HyperLinkInterface`. These fields can be selected directly on any Hyper field, without a concrete link-type fragment. All interface fields are nullable. `ArrayType` is a JSON scalar and does not take a nested selection.

| Field | Type | Description
| - | - | -
| `ariaLabel`| `String` | The `aria-label` attribute for the link.
| `classes`| `String` | The `class` attribute for the link.
| `element`| `ElementInterface` | The element (if provided) for the link.
| `isElement`| `Boolean` | Whether the chosen link value is an element.
| `isEmpty`| `Boolean` | Whether the link has no meaningful saved content.
| `link`| `String` | The HTML output for an `<a>` element.
| `linkText`| `String` | Link Text with layout defaults and type-specific fallbacks, including element titles.
| `customLinkText`| `String` | Only the Link Text field value, with no fallbacks. Null when blank—use for explicit defaults in your API client.
| `linkUrl`| `String` | The URL for the link.
| `linkValue`| `String` | Raw link data as a JSON string (full embed metadata for Embed links).
| `html`| `String` | Embed HTML (`code`) when this is an Embed link; otherwise null.
| `iframeSrc`| `String` | The `src` of the first iframe in embed HTML, when present.
| `embedImage`| `String` | Thumbnail/image URL from embed metadata, when present.
| `providerName`| `String` | oEmbed provider name for Embed links (e.g. YouTube), when present.
| `fields`| `String` | Custom layout field values as a JSON object keyed by handle (no type cast required).
| `newWindow`| `Boolean` | Whether the link should open in a new window.
| `target`| `String` | The `target` attribute for the link.
| `text`| `String` | The fully derived link label (custom text, type fallbacks, then field placeholder or plugin default).
| `title`| `String` | The `title` attribute for the link.
| `type`| `String` | The link type.
| `url`| `String` | The URL for the link.
| `urlPrefix`| `String` | The URL prefix for the link.
| `urlSuffix`| `String` | The URL suffix for the link.
| `linkUri`| `String` | The link URI, if an element-based link.
| `customAttributes`| `ArrayType` | The custom attributes for the link.

Use a concrete link-type fragment for custom layout fields. [GraphQL Fields](/reference/graphql-fields#concrete-types-and-custom-fields) explains type names, layout aliases, schema restrictions, and unavailable link types.
