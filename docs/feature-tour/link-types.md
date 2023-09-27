# Link Types
Hyper provides many different types of links to pick from, each with their own use-cases and specifics. Each field can enable or disable specific link types as required.

## Asset
Allows users to select an Asset element to link to.

## Category
Allows users to select a Category element to link to.

## Custom
Similar to a URL in almost every way, except validation rules. Use this for more advanced links such as custom protocols like `skype:example123?chat` and more.

## Email
Allows users to enter an email. This will be prefixed automatically with `mailto:`.

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
Looking to create your own link type, or extend an existing one? Check out our guide on [Link Types](docs:developers/link-type) for more.
:::
