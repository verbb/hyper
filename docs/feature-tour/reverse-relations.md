# Reverse Relations

Use a reverse relation to find content that links to the entry you are viewing. For example, a resource page could display a list of News articles that recommend it through a Hyper field.

## Find Pages Linking Here

Suppose each News entry has a Hyper field with the handle `relatedContent`. On a resource entry’s Twig template, `entry` is the resource you want to look up. Place this snippet where its incoming links should appear:

```twig
{% set linkingPages = craft.hyper.getRelatedElements({
    relatedTo: {
        targetElement: entry,
        field: 'relatedContent',
    },
    criteria: {
        section: 'news',
    },
}).all() %}

{% if linkingPages %}
    <h2>Recommended in These Articles</h2>
    <ul>
        {% for page in linkingPages %}
            <li><a href="{{ page.url }}">{{ page.title }}</a></li>
        {% endfor %}
    </ul>
{% endif %}
```

`targetElement` is the resource being linked to. `field` identifies the Hyper field on the articles, and `criteria.section` limits the results to News. Save an article linking to this resource, then view the resource page: that article should appear in the list. If no articles link to it, the heading and list are omitted.

Hyper updates its relation index when the owning elements are saved. Craft’s native `relatedTo` parameter does not query Hyper fields; use `craft.hyper.getRelatedElements()` for this task.

## Work with Multiple Sites

Pass the target element from the site whose incoming links you want to inspect. The optional `site` parameter selects the site for the returned articles and defaults to the current site. It does not change which translation of the target you supplied.

See [Reverse Lookup Parameters](/reference/loading-links#reverse-lookup-parameters) for additional query options and the PHP equivalent. To access a destination from an individual link, see [Element Links](/feature-tour/element-links).
