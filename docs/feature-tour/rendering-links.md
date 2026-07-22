# Rendering Links
There are several ways to render a [Link](docs:reference/link) object. 

:::tip
For this page, we'll assume your Hyper field has the handle `myLinkField`, so be sure to substitute that with your own link handle. We'll also assume we're on a single entry template, and there's a `entry` variable available.
:::

By default, outputting the value of a Hyper field will return the URL.

```twig
{{ entry.myLinkField }}
{{ entry.myLinkField.url }}

{# Outputs: http://my-site.test/some-url (both are the same) #}
```

Next, are common attributes to build the `<a>` anchor tag to generate a URL.

```twig
{% set url = entry.myLinkField.url %}
{% set text = entry.myLinkField.text %}
{% set target = entry.myLinkField.target %}

<a href="{{ url }}" target="{{ target }}">{{ text }}</a>

{# Outputs: <a href="http://my-site.test/some-url" target="_blank">Some URL</a> #}
```

But, a shorthand version of this is to use `getLink()`.

```twig
{{ entry.myLinkField.getLink() }}

{# Outputs: <a href="http://my-site.test/some-url" target="_blank" rel="noopener noreferrer">Some URL</a> #}
```

The benefit of using `getLink()` is that it'll automatically add any custom attributes, URL suffix, classes, Aria label, Link title and more to the `<a>` tag. Notice how the `rel` attribute is also added if we've selected to open this link in a new window?

You can also pass in any extra attributes you require:

```twig
{{ entry.myLinkField.getLink({
    class: 'text-black font-bold',
    'data-link': 'external',
}) }}

{# Outputs: <a href="http://my-site.test/some-url" class="text-black font-bold" data-link="external">Some URL</a> #}
```

You can also override the link text using `text`.

```twig
{% set linkContent %}
    <svg ... />
    Check out this link
{% endset %}

{{ entry.myLinkField.getLink({
    text: linkContent,
}) }}
```

## Link Type
You may want to customise the rendering of a link depending on it's type. You'll need to use the full class for the link type to compare:

```twig
{% if entry.myLinkField.type == 'verbb\\hyper\\links\\Url' %}
    {# Output for a URL link #}
{% elseif entry.myLinkField.type == 'verbb\\hyper\\links\\Entry' %}
    {# Output for an Entry link #}
{% endif %}
```

Available types:
- `verbb\\hyper\\links\\Asset`
- `verbb\\hyper\\links\\Category`
- `verbb\\hyper\\links\\Custom`
- `verbb\\hyper\\links\\Email`
- `verbb\\hyper\\links\\Embed`
- `verbb\\hyper\\links\\Entry`
- `verbb\\hyper\\links\\Phone`
- `verbb\\hyper\\links\\Site`
- `verbb\\hyper\\links\\Url`
- `verbb\\hyper\\links\\User`

## Link Value
You can access the raw "Link Value" if you require. This is a general purpose setting that varies depending on the link type.

```twig
{{ entry.myLinkField.linkValue }}

{# URL link type #}
{# http://my-site.test #}

{# Email link type #}
{# info@my-site.test #}

{# Phone link type #}
{# 1234 567 890 #}

{# Element link type #}
{# 25251 (the ID of the linked element) #}

{# Site link type #}
{# 76974830-73a5-45fb-9c73-72ac8c8981dc (the UID of the linked site) #}

{# Embed link type #}
{# {"title":"lofi hip hop radio - beats to relax/study to","description"... #}
```

## Native Fields

For the available native fields, access them as attributes on the link:

```twig
{{ entry.myLinkField.ariaLabel }}
{{ entry.myLinkField.linkText }}
{{ entry.myLinkField.customLinkText }}
{{ entry.myLinkField.linkTitle }}
{{ entry.myLinkField.urlSuffix }}
```

::: tip
For the full attribute and method reference, see [Link](/reference/link) and [Link Type Settings](/reference/link-type-settings).
:::

### Custom label vs resolved label
`linkText` can include type-specific fallbacks (for example, the linked entry’s title when the Link Text field is empty). If you want **only** what the author typed—so a blank field stays blank and you can supply your own default in Twig—use `customLinkText`:

```twig
{% set label = entry.myLinkField.customLinkText ?? 'Our default' %}
<a href="{{ entry.myLinkField.url }}">{{ label }}</a>
```

For the fully derived label (custom text, else element title where applicable, else field placeholder / “Read more”), use `text` or `getLink()`.

## Empty
You can check if a Hyper field has a value with `isEmpty()`.

```twig
{% if not entry.myLinkField.isEmpty() %}
    {{ entry.myLinkField.getLink() }}
{% endif %}
```

## Custom Fields
If you have any custom fields added to your link type, you can access them as you would directly from an element using their field handle.

```twig
{{ entry.myLinkField.myCustomField }}
{{ entry.myLinkField.myEntriesField.one().title }}
```

## Element Links
For an element-based link, you can get the linked-to element. You can also use `hasElement()` to check if the link is linking to an element.

```twig
{% if entry.myLinkField.hasElement() %}
    {% set linkElement = entry.myLinkField.getElement() %}

    {{ linkElement.title }}
    {{ linkElement.entryCustomField }}
{% endif %}
```

### URL fragments (anchors)

To link to a fragment on an element URL (for example `#pricing`), use the **URL Suffix** field on the link (Advanced tab by default). Hyper appends the suffix when building `url` and `getLink()`:

```twig
{# Link value: entry about page, URL Suffix: #team #}
{{ entry.myLinkField.url }}
{# https://example.test/about#team #}
```

:::tip
Don't forget if you want the Title or URL of an element, it's more performant to use `entry.myLinkField.title` or `entry.myLinkField.url`.
:::

### Eager loading fields on linked elements

When you loop over many entries and need fields **on the linked element** (not just the link URL/text), use Craft's normal `with()` syntax on the **owner** query:

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

Supported paths:

| Path | Effect |
| --- | --- |
| `{hyperField}.linkedElements` | Batch-load linked target elements |
| `{hyperField}.linkedElements.{fieldHandle}` | Batch-load targets and eager-load `{fieldHandle}` on each |
| `{hyperField}.linkedElements.{fieldHandle}.{nested}` | Nested Craft `with` paths on the target batch query |

Hyper intercepts these paths during query preparation and applies them when batch-loading linked elements.

For the full priming behaviour and path reference, see [Eager Loading](/feature-tour/eager-loading).

## Embed Links
Embed links store extra information about the fetched page. This could be a Twitter post, a YouTube video, or a SoundCloud song.

```twig
{# Example URL: https://www.youtube.com/watch?v=jfKfPfyJRdk #}

{{ entry.myLinkField.getLink() }}
{# Outputs: <a href="https://www.youtube.com/watch?v=jfKfPfyJRdk">lofi hip hop radio - beats to relax/study to</a> #}

{{ entry.myLinkField.getHtml() }}
{# Outputs: <iframe src="https://www.youtube.com/embed/jfKfPfyJRdk" title="lofi hip hop radio - beats to relax/study to"></iframe> #}

{{ entry.myLinkField.getIframeSrc() }}
{# Outputs: https://www.youtube.com/embed/jfKfPfyJRdk #}

{{ entry.myLinkField.getData() }}

{# {
    title: 'lofi hip hop radio - beats to relax/study to',
    description: '🤗 Thank you for listening, I hope you will have a good time here💽',
    ...
} #}
```

### Embed allowlists
Each Embed link type can restrict **Allowed Domains** in field settings (e.g. `youtube.com` and `youtu.be` for a video-only field). Empty domain lists fall back to the plugin `embedAllowedDomains` config.

### YouTube thumbnails
Hyper stores the thumbnail URL returned by oEmbed in `linkValue.image` (and GraphQL `embedImage`). YouTube often supplies `hqdefault.jpg`. Hyper upgrades that to `maxresdefault.jpg` when the HD asset exists. You can also enable `resolveHiResEmbedImage` in config to compare all candidate images by dimensions (slower).

## Multiple Links

If your Hyper field allows multiple links, the field value is a [LinkCollection](/reference/link-collection) — iterate it or use array access.

```twig
{% for link in entry.myLinkField %}
    {{ link.getLink() }}
{% endfor %}
```

:::tip
Multi-link fields will still work with the previous examples (e.g. `myLinkField.url`, etc), but you'll only ever be outputting the first link in the field. As such, you'll want to adjust your templates to loop through a collection.
:::
