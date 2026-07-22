# GraphQL
Hyper supports accessing [Link](docs:reference/link) objects via GraphQL. Be sure to read about [Craft's GraphQL support](https://craftcms.com/docs/4.x/graphql.html).

## Links
In order to query a link, you'll need to first query the element that the Hyper field is attached to.

### Example

:::code
```graphql GraphQL
{
    entries (section: "news", limit: 1) {
        myLinkField {
            url
            text
        }
    }
}
```

```json JSON Response
{
    "data": {
        "entries": {
            "myLinkField": [
                {
                    "url": "http://my-site.test/some-url",
                    "text": "Some Text"
                }
            ]
        }
    }
}
```
:::

:::tip
Note that for consistency with the GraphQL spec, the value for a Hyper field will always be an array of LinkInterface's, regardless of whether your Hyper field is set to be a multi-link field or not.
:::

### The `LinkInterface` interface
This is the interface implemented by all links.

| Field | Type | Description
| - | - | -
| `ariaLabel`| `String` | The `aria-label` attribute for the link.
| `classes`| `String` | The `class` attribute for the link.
| `element`| `Element` | The element (if provided) for the link.
| `isElement`| `Boolean` | Whether the chosen link value is an element.
| `isEmpty`| `Boolean` | Whether a link has been set for the field.
| `link`| `String` | The HTML output for a `<a>` element.
| `linkText`| `String` | The resolved link label before layout defaults (custom Link Text if set; otherwise type-specific fallbacks such as element titles when the Link Text field is empty).
| `customLinkText`| `String` | Only the Link Text field value, with no fallbacks. Null when blank—use for explicit defaults in your API client.
| `linkUrl`| `String` | The url for the link.
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
| `url`| `String` | The url for the link.
| `urlPrefix`| `String` | The url prefix for the link.
| `urlSuffix`| `String` | The url suffix for the link.
| `linkUri`| `String` | The link URI, if an element-based link.
| `customAttributes`| `ArrayType` | The custom attributes for the link.


#### Custom Fields
Custom fields on a link type are available in two ways:

1. **Typed fragments** — cast to the concrete `*_LinkType` for typed GraphQL fields.
2. **`fields` JSON bag** — read all layout field values without knowing the link type (useful for headless clients that branch in application code).

```gql
myLinkField {
    url
    text
    type
    fields

    ... on myLinkField_Url_LinkType {
        plainText
    }

    ... on myLinkField_CustomUrl_LinkType {
        anotherField
    }
}
```

#### Embed links
For Embed link types, prefer the dedicated interface fields over parsing `linkValue`:

```gql
myLinkField {
    url
    text
    html
    iframeSrc
    embedImage
    providerName
    linkValue
}
```

`iframeSrc` extracts the first iframe `src` from the stored embed HTML. `linkValue` remains the full JSON metadata blob for advanced use.

For typed fragments, use `PascalCase` of the link type **handle** (not the CP label). For example, handle `custom-url` becomes `myLinkField_CustomUrl_LinkType`.
