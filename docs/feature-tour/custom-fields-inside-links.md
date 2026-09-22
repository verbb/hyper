# Custom Fields Inside Links

Add custom fields to a link when editors need information beyond its destination and label. For example, a related-resources list could give each link a short summary that appears below its title.

## Add a Summary

Create a Plain Text field named `Summary` with the handle `summary` in **Settings → Fields**. Open your Hyper field’s link type settings, or its shared [Link Type Config](/feature-tour/link-type-configs), and select the type that needs the summary.

Under **Link Fields**, add Summary to the layout and save your changes. Edit an entry containing the Hyper field, add a link of that type and enter a summary. Save the entry. The summary belongs to that individual link, so two links to the same destination can have different summaries.

In the entry’s Twig template, access the custom field through the link. This example assumes a multi-link Hyper field with the handle `resources`, with Summary added to each enabled type:

```twig
<ul>
    {% for link in entry.resources %}
        <li>
            {{ link.getLink() }}
            {% if link.summary %}
                <p>{{ link.summary }}</p>
            {% endif %}
        </li>
    {% endfor %}
</ul>
```

Check that the saved summary appears below the correct link. Fields on a selected destination are separate: `link.summary` reads the summary inside the link, while `link.getElement().summary` would read a field on the destination element.

## Filter by Custom Fields

To show only featured resources, add a Lightswitch field with the handle `featured` to the same link layouts. Enable it on the links to include, save the entry, and use this in the entry template:

```twig
{% for link in entry.resources.where({ featured: true }) %}
    {{ link.getLink() }}
{% endfor %}
```

Only matching links with destinations are returned. The original collection remains unchanged. Read custom fields on each Link, or on the result of `first()` after checking it exists. The collection itself exposes selection methods and a fixed set of rendering conveniences. See [Filtering Links](/reference/link-collection#filter-links) for numeric comparisons, dates and ordering.

## Arrange Tabs

Keep **Link** on the first layout tab so editors see the destination when opening the link. Use another tab for supporting fields if the link needs more information. Editors switch tabs in the link header. Native fields such as Link Text and URL Suffix are listed in [Link Type Settings](/reference/link-type-settings#native-layout-fields).

## Matrix Fields

Matrix fields inside a Hyper link use an inline editor, where you can add, duplicate, reorder and delete blocks. Those blocks remain inline even if the Matrix field uses cards elsewhere. You can copy and paste between compatible Matrix fields inside Hyper links, using a clipboard separate from standalone Matrix fields.

A Hyper link is not independently saved as a Craft element, so Matrix card and index editors are unavailable inside links.

## Asset Uploads

Temporary uploads selected in a link’s Assets field move to that field’s configured upload folder when the owning element is saved. For dynamic upload paths, use stable link values such as `{uid}` rather than a link element `{id}`.

An unresolved temporary destination can remain pending on a draft; publishing requires a permanent destination. If publishing reports an upload-location problem, check the Assets field’s upload folder and any values used in its dynamic path.
