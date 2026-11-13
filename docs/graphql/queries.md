# Queries

Use GraphQL when a separate frontend needs the destinations and labels stored in Hyper fields. Hyper doesn't add its own top-level queries. Instead, you query the element that holds the field through Craft's own queries, such as `entries`, and select the link properties your frontend needs.

## Prepare an Entry and Schema

This example assumes a News section with the handle `news`, an entry type with the handle `newsPost`, and a Hyper field with the handle `resourceLinks` attached to that entry type. A handle is the name used in code. Replace these sample handles with your site’s values.

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

Both `entries` and `resourceLinks` are lists. Hyper returns a list even when **Enable Multiple Links** is off. The collection excludes links without a resolved destination, including Passive labels and unavailable targets. Use the field’s `empty` argument to include or inspect URL-less rows. Add another link with multiple links enabled and rerun the query to see a second item in `resourceLinks`.

If Craft reports an unknown field or type, check the entry type handle, field placement and active schema permissions. If the result contains no entries, check publication status and the selected site.

## Include Empty Destinations

A Hyper field accepts one argument, `empty`, which follows the collection’s destination rules.

| Value | Returns |
| --- | --- |
| `false` | Links with a resolved URL. This is the default. |
| `true` | Links without a resolved URL. |
| `null` | Links with or without a resolved URL. |

For example, include Passive labels and URL-less User selections in an entry fragment with:

```graphql
resourceLinks(empty: null) {
    linkText
    url
    isEmpty
}
```

This is a partial selection to place inside the entry-type fragment shown above. Your client decides how to display each returned row. Element links whose destinations are unavailable or outside the active schema appear as null list items, including when `empty: null` is used, and none of their link fields are resolved. Twig’s `where()`, ordering and limit methods are not GraphQL arguments.

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

The result includes `summary` on matching URL links. If your frontend needs a common representation across different layouts, request `fields` to receive permitted custom values as a JSON string instead. See [Interfaces & Types](/graphql/interfaces-types#concrete-link-types-and-custom-fields) for the returned format.

## Read Embed Metadata

For a field containing Embed links, request `html`, `iframeSrc`, `embedImage` and `providerName` alongside `url` and `text`. You can add these to the `resourceLinks` selection in the first query without a type fragment. They return null when the stored link has no corresponding metadata.

Check the stored embed in the editor before relying on its HTML or thumbnail. [Rendering Links](/feature-tour/rendering-links#embed-links) explains the difference between an anchor and embedded output. The [HyperLinkInterface reference](/graphql/interfaces-types#the-hyperlinkinterface-interface) lists the available response fields.

Every link in the list implements `HyperLinkInterface`, and each link type has its own concrete type for custom fields. [Interfaces & Types](/graphql/interfaces-types) lists every field you can select and explains how the concrete type names are built.
