# Eager Loading

When a page displays many links, loading each destination separately can add repeated database queries. Hyper batches linked elements on normal frontend site requests. You can also request fields on those destinations together, such as the thumbnails for a related-articles list.

## Render Plain Links

Use `link.getLink()`, or `link.url` and `link.text` for custom markup. Element links resolve their destinations and fallback labels through the selected elements. Hyper’s automatic batching helps avoid a separate target lookup for every link; the work still involves loading elements.

You do not need an explicit eager-loading path for an ordinary list of anchors on a frontend page. Outside normal site requests, such as a console command, use an explicit path when you need to load targets together. See [Loading Paths](/reference/loading-links#loading-paths).

## Load Thumbnails for Linked Entries

Suppose your News section has the handle `news` and a single-link Hyper field called `relatedArticle`. Its Entry links select other News entries. Those entries have an Assets field called `thumbnail`.

Place this example in the Twig template that displays your News listing. Change the section and field handles to match your site:

```twig
{% set articles = craft.entries()
    .section('news')
    .with(['relatedArticle.linkedElements.thumbnail'])
    .all() %}

<ul>
    {% for article in articles %}
        {% set link = article.relatedArticle.first() %}
        {% set destination = link ? link.getElement() : null %}
        {% if destination and link.url %}
            {% set thumbnail = destination.thumbnail.one() %}
            <li>
                <a href="{{ link.url }}">
                    {% if thumbnail %}
                        <img src="{{ thumbnail.url }}" alt="">
                    {% endif %}
                    {{ link.text }}
                </a>
            </li>
        {% endif %}
    {% endfor %}
</ul>
```

The path starts with `relatedArticle`, the Hyper field on each queried article. `linkedElements` selects its destinations, and `thumbnail` loads the Assets field on those destinations. The example checks for a destination and an image, so an unselected or unavailable link does not break the listing. The image has an empty alt attribute because the adjacent link text describes the destination.

## Nested Fields

For Hyper fields inside Matrix or Neo, prefix the path with the containing field’s handle. For example, `contentBlocks.relatedArticle.linkedElements.thumbnail` loads thumbnails for links inside `contentBlocks`. See [Loading Paths](/reference/loading-links#loading-paths) for the supported forms.

Hyper fields inside Vizy can resolve individual destinations with `getElement()`, but do not support this automatic batching or nested `with()` path. Keep that limitation in mind when deciding where to store a large related-content list.

## Check Your Page

Compare the page’s database queries before and after adding the path, using Craft’s debug toolbar in development. The links and thumbnails should remain the same while repeated target-field queries decrease. [Checking Link Performance](/guides/developers/checking-link-performance) walks through that comparison.

GraphQL has its own selection syntax and uses Hyper’s target batching. See [GraphQL](/developers/graphql) for an example query.
