# Rendering Links
There are several ways to render a [Link](docs:developers/link) object. 

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
For the available native fields, you can access them as you'd expect as attributes:

```twig
{{ entry.myLinkField.ariaLabel }}
{{ entry.myLinkField.customText }}
{{ entry.myLinkField.title }}
{{ entry.myLinkField.urlSuffix }}
```

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

:::tip
Don't forget if you want the Title or URL of an element, it's more performant to use `entry.myLinkField.title` or`entry.myLinkField.url`.
:::

## Embed Links
Embed links store extra information about the fetched page. This could be a Twitter post, a YouTube video, or a SoundCloud song.

```twig
{# Example URL: https://www.youtube.com/watch?v=jfKfPfyJRdk #}

{{ entry.myLinkField.getLink() }}
{# Outputs: <a href="https://www.youtube.com/watch?v=jfKfPfyJRdk">lofi hip hop radio - beats to relax/study to</a> #}

{{ entry.myLinkField.getHtml() }}
{# Outputs: <iframe src="https://www.youtube.com/embed/jfKfPfyJRdk" title="lofi hip hop radio - beats to relax/study to"></iframe> #}

{{ entry.myLinkField.getData() }}

{# {
    title: 'lofi hip hop radio - beats to relax/study to',
    description: '🤗 Thank you for listening, I hope you will have a good time here💽',
    ...
} #}
```

## Multiple Links
If your Hyper field is configured to allow multiple links in a single field, you'll want to treat the value of your Hyper field as an array of [Link](docs:developers/link) objects.

```twig
{% for link in entry.myLinkField %}
    {{ link.getLink() }}
{% endfor %}
```

:::tip
Multi-link fields will still work with the previous examples (e.g. `myLinkField.url`, etc), but you'll only ever be outputting the first link in the field. As such, you'll want to adjust your templates to loop through a collection.
:::
