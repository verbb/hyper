# Checking Link Performance

This guide helps you check whether a page loads linked content efficiently. You’ll compare a plain list of links with a list that also displays thumbnails, then check whether eager loading removes repeated queries.

Use a development site with Craft’s debug toolbar enabled for your account. You need a News section (`news`), a Hyper field (`relatedArticle`) on those entries, and an Assets field (`thumbnail`) on the entries selected as destinations. Populate several News entries with related links and thumbnail assets. Use enough different destinations to see repeated work; one link will not show a batching benefit clearly.

## Establish a Plain-Link Page

Place this in the Twig template you want to measure, inside its existing content block if it extends a layout:

```twig
{% set articles = craft.entries().section('news').all() %}
<ul>
    {% for article in articles %}
        {% if article.relatedArticle.url %}
            <li>{{ article.relatedArticle.getLink() }}</li>
        {% endif %}
    {% endfor %}
</ul>
```

Open the page and follow a link to confirm the content is correct. Return and inspect the database queries in Craft’s debug toolbar. Note the query count and look for queries fetching the selected destinations. Hyper batches targets on normal frontend requests, so plain links can already benefit without an explicit loading path.

## Add Destination Thumbnails

Replace the earlier template block with the complete [thumbnail example](/feature-tour/eager-loading#load-thumbnails-for-linked-entries). It uses `with(['relatedArticle.linkedElements.thumbnail'])` to load the target entries and their thumbnails together.

Reload the page and check that each thumbnail belongs to the correct destination. Links without thumbnails should still show their text. Keep this output as the version you are measuring.

## Compare the Queries

Temporarily remove only the `.with(['relatedArticle.linkedElements.thumbnail'])` line and reload. Inspect the database queries again. Accessing each target’s Assets field separately can add repeated queries; compare that pattern with the version using `with()`.

Restore the `with()` line and repeat the check with the same content and cache conditions. You are looking for fewer repeated field queries with identical visible output. Total query counts and timings also depend on the rest of your template, plugins, transforms and caches, so there is no universal expected number.

If nothing changes, confirm that the template actually reads the destination’s thumbnail, the handle in the path matches the field, and a full-page cache is not bypassing the template. Automatic target loading alone does not load every custom field on those targets.

## Apply the Result Elsewhere

Keep explicit paths on queries that read fields from linked destinations. Plain anchor lists generally need only the standard rendering methods on frontend site requests. Console code and nested content can have different loading conditions; consult [Loading Links](/reference/loading-links) before applying the same assumption there.

Incoming-link queries answer a separate question: which entries link to this target? Use [Reverse Relations](/feature-tour/reverse-relations) for that task.
