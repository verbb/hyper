# Rendering Links

Use Hyper’s link renderer when you want an anchor containing the saved destination, label and attributes. Use individual properties when your design needs different markup, such as a linked card or a navigation heading without a destination.

The examples on this page belong in an entry’s Twig template. They assume `entry` is the current entry and its Hyper field has the handle `myLinkField`. Replace that handle with your own. If you have not created and attached a field yet, follow [Creating and Displaying Your First Links](/guides/templating/creating-and-displaying-your-first-links).

## Render a Single Link

```twig
{{ entry.myLinkField.getLink() }}
```

For a URL of `https://example.test/contact` and Link Text of `Contact us`, the result is:

```html
<a href="https://example.test/contact">Contact us</a>
```

`getLink()` includes the saved classes, custom attributes, URL suffix and other native link attributes. When the link opens in a new window, it also adds the corresponding target and relationship attributes. If there is no usable URL, it outputs nothing.

For a URL string alone, use `entry.myLinkField.url` or output `entry.myLinkField` directly. The collection’s shortcuts use its first non-empty link, even when multiple links are enabled. Loop over the field to display every link.

## Add Attributes

Pass attributes to `getLink()` to override the saved values for this rendering:

```twig
{{ entry.myLinkField.getLink({
    class: 'button',
    'data-location': 'page-footer',
}) }}
```

This renders the link with your button class and data attribute. Changing the template does not change the saved link. For individual native attributes and their meaning, see [Link](/reference/link#attributes).

## Choose a Label

`text` is the display label. It uses the entered Link Text, layout defaults and type-specific fallbacks such as an entry’s title. It can then fall back to the Link Text placeholder or “Read more”. For ordinary links it returns nothing without a usable URL.

Use `customLinkText` when you want only the editor’s input, with no fallback. It returns `null` when blank:

```twig
{% if entry.myLinkField.url %}
    {{ entry.myLinkField.getLink({
        text: entry.myLinkField.customLinkText ?? 'Contact our team',
    }) }}
{% endif %}
```

The link’s `title` is its HTML title attribute. To read a selected entry’s title, get the [linked element](/feature-tour/element-links#linked-element).

### Include Markup in the Label

A Twig capture can supply template-authored markup as the label:

```twig
{% set linkContent %}
    <span aria-hidden="true">→</span>
    View the resource
{% endset %}

{{ entry.myLinkField.getLink({text: linkContent}) }}
```

Hyper escapes ordinary strings and preserves trusted Twig markup. Keep editor-provided text escaped; do not apply `raw` to it to make HTML render.

## Multiple Links

Iterate the field directly to render its non-empty links in saved order:

```twig
{% if entry.myLinkField.exists() %}
    <ul>
        {% for link in entry.myLinkField %}
            <li>{{ link.getLink() }}</li>
        {% endfor %}
    </ul>
{% endif %}
```

A link without a resolved URL is excluded automatically, even if it has an entered label or custom fields. This also excludes links to unavailable entry targets. The loop’s indexes and length describe the links actually returned. The stored rows remain available in the editor.

### Select Particular Links

Filter directly on the collection. For example, if you have a custom URL type with the handle `externalResource`:

```twig
{% for link in entry.myLinkField.where({ handle: 'externalResource' }) %}
    {{ link.getLink() }}
{% endfor %}
```

Filters return a new selection, leaving ordinary reads of the field unchanged. Add custom-field conditions, ordering or limits when needed; the [LinkCollection reference](/reference/link-collection#filter-links) explains those options.

### Passive Labels

A Passive link has no destination. Include it explicitly with `empty(null)` when your design uses navigation headings:

```twig
{% set items = entry.myLinkField.empty(null) %}
{% if items.exists() %}
    <ul>
        {% for link in items %}
            {% if not link.isEmpty() %}
                <li>{{ link.getLink() }}</li>
            {% elseif link.type == 'verbb\\hyper\\links\\Passive' and link.text %}
                <li><span>{{ link.text }}</span></li>
            {% endif %}
        {% endfor %}
    </ul>
{% endif %}
```

This renders Passive labels as text and omits other unfinished links. `empty(null)` includes every stored row, so a collection containing only unfinished non-Passive rows can produce an empty list wrapper in this example. Use the ordinary filtered loop when you only need clickable links.

## Custom Fields

Fields added to a link’s layout are available by handle on that link. For example, `link.summary` reads a Plain Text field called `summary`. See [Custom Fields Inside Links](/feature-tour/custom-fields-inside-links) for setup and a rendering example.

To read fields on the selected destination, get the element first and check that it exists. The [Eager Loading example](/feature-tour/eager-loading#load-thumbnails-for-linked-entries) shows this with thumbnail assets.

## URL Fragments (Anchors)

To point at a section within a destination page, add **URL Suffix** to the link’s layout and enter a fragment such as `#team`. It is included in both `url` and `getLink()`:

```twig
{{ entry.myLinkField.url }}
{# For an About entry with suffix #team: https://example.test/about#team #}
```

The destination template must contain an element with the corresponding ID, such as `<section id="team">`. Check the link in your browser to confirm it reaches that section.

## Embed Links

An Embed link stores metadata about a remote page. `getLink()` renders a link to that page; `getHtml()` renders its stored embed code when available. For a single-link field configured to accept Embed links:

```twig
{% set embed = entry.myLinkField.first() %}
{% if embed and embed.getHtml() %}
    {{ embed.getHtml() }}
{% elseif embed and embed.url %}
    {{ embed.getLink() }}
{% endif %}
```

This displays the embedded content, falling back to a normal link when no embed HTML is available. A provider may return an iframe, other markup or no embeddable content. Configure [allowed domains](/get-started/configuration#embed-domains) before relying on a particular provider.

Hyper uses the stored thumbnail metadata for Embed images. For YouTube, it checks whether a higher-resolution alternative to `hqdefault.jpg` exists. The optional `resolveHiResEmbedImage` setting compares additional candidates and can make fetching metadata slower. See the [Embed reference](/reference/link#embed-links) for the available helpers.
