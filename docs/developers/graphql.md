# GraphQL
Hyper supports accessing [Link](docs:developers/link) objects via GraphQL. Be sure to read about [Craft's GraphQL support](https://craftcms.com/docs/4.x/graphql.html).

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
| `linkText`| `String` | The text for the link.
| `linkUrl`| `String` | The url for the link.
| `newWindow`| `Boolean` | Whether the link should open in a new window.
| `target`| `String` | The `target` attribute for the link.
| `text`| `String` | The text for the link.
| `title`| `String` | The `title` attribute for the link.
| `type`| `String` | The link type.
| `url`| `String` | The url for the link.
| `urlPrefix`| `String` | The url prefix for the link.
| `urlSuffix`| `String` | The url suffix for the link.

#### Custom Fields
In order to access custom fields on a Link Type, you'll need to use the correct type. This will be a `PascalCase` string of the Link Type defined in your field settings. This is because each Link Type has a different field layout, with different fields. You'll need to "cast" the correct Link Type depending on what field you need to query.

```gql
myLinkField {
    url
    text

    ... on myLinkField_Url_LinkType {
        plainText
    }

    ... on myLinkField_CustomUrl_LinkType {
        anotherField
    }
}
```

For example, for any of the default Link Types (`Asset`, `Entry`, `Custom`, `Url`, etc.) you can use `myLinkField_Url_LinkType` and then any custom field handles within that. For any additional, new Link Types that you create over the default ones, use `PascalCase` for the "Label". For example, for a Link Type with a label "Custom URL", this would be `myLinkField_CustomUrl_LinkType`.