# Link Types
Hyper provides many different types of links to pick from, each with their own use-cases and specifics. Each field can enable or disable specific link types as required.

## Passive

Label-only links with **no URL target** — useful for menu section headings, disabled-looking items, or grouping labels in a multi-link field. Passive links can still have link text, classes, and custom fields; `url` resolves empty.

## Asset
Allows users to select an Asset element to link to.

## Category
Allows users to select a Category element to link to.

## Custom
Similar to a URL in almost every way, except validation rules. Use this for more advanced links such as custom protocols like `skype:example123?chat`, full `mailto:user@example.com?subject=…` URIs, and more.

## Email
Allows users to enter an email. This will be prefixed automatically with `mailto:`.

Email values must be a valid email address (Yii’s email validator). Query-string parameters such as `?subject=` or `?body=` are **not** accepted in the Email field itself — those make the value a mailto URI, not an email address.

To append parameters to a mailto link, use either:

- **URL Suffix** on the Email link (for example `?subject=Hello&body=…`), which Hyper appends after the `mailto:` address when building `url` / `getLink()`, or
- a **Custom** link type and paste a full `mailto:…` URI (handy when using generators like [mailtolink.me](https://mailtolink.me)).

## Embed
Similar to a URL where users enter an absolute URL. In addition, Hyper will fetch information about the URL and store that alongside the URL. This is useful for being able to access data about the URL.

For example, you could embed a YouTube video link in the Hyper field.

```twig
{# Example URL: https://www.youtube.com/watch?v=jfKfPfyJRdk #}

{{ entry.myLinkField.getLink() }}
{# Outputs: <a href="https://www.youtube.com/watch?v=jfKfPfyJRdk">lofi hip hop radio - beats to relax/study to</a> #}

{{ entry.myLinkField.getHtml() }}
{# Outputs: <iframe src="https://www.youtube.com/embed/jfKfPfyJRdk" title="lofi hip hop radio - beats to relax/study to"></iframe> #}
```

## Entry
Allows users to select an Entry element to link to.

## Phone
Allows users to enter a phone number. This will be prefixed automatically with `tel:`.

## Product
Allows users to select a [Commerce Product](https://plugins.craftcms.com/commerce) element to link to.

## Shopify Product
Allows users to select a [Shopify Product](https://plugins.craftcms.com/shopify) element to link to.

## Site
Allows users to select a Site to link to.

## URL
Allows users to enter a general purpose, relative or absolute URL.

## User
Allows users to select an User element to link to. Because Users don't inherently have a URL, unlike other elements, you'll likely want to use the URL suffix field, or call `getElement()` to write your own logic for generating the URL to a user.

For example, you could use the following:

```twig
{% set userSlug = entry.myLinkField.getElement().fullName | kebab %}

{{ siteUrl(userSlug) }}

{# Generates: http://my-site.test/josh-crawford #}
```

## Variant
Allows users to select a [Commerce Variant](https://plugins.craftcms.com/commerce) element to link to.

:::tip
Looking to create your own link type, or extend an existing one? Check out [Link Types](/developers/link-types) for more.
:::
