# Importing Links with Feed Me

This guide imports one News entry with two resource links. Each link has its own destination, label and summary. You’ll map the feed values to Hyper, run the import and inspect the saved result.

Start with Hyper and [Feed Me](https://plugins.craftcms.com/feed-me) installed in a development Craft project. You need access to Feed Me and field settings, and permission to create entries. Create a News section and entry type if your site does not already have a suitable destination for this example.

## Prepare the Hyper Field

Create a Hyper field named Resource Links with the handle `resourceLinks`, choose **Custom** for its Link Type Config and enable URL. Turn on **Enable Multiple Links**. Create a Plain Text field named Summary with the handle `summary`, then add it to the URL type’s **Link Fields** layout. Save the field and add it to the News entry type’s layout.

This gives Feed Me a place to store the list and the summary on each link. [Custom Fields Inside Links](/feature-tour/custom-fields-inside-links) explains that layout setup in more detail.

## Create a Sample Feed

Save this as `hyper-resources.json` in your development site’s public web directory, so Feed Me can read it through the site’s URL:

```json
{
    "articles": [
        {
            "title": "Useful Resources",
            "slug": "useful-resources",
            "links": [
                {
                    "url": "https://verbb.io",
                    "text": "Visit Verbb",
                    "summary": "Plugins for your Craft site."
                },
                {
                    "url": "https://craftcms.com",
                    "text": "Craft CMS",
                    "summary": "Learn about the CMS."
                }
            ]
        }
    ]
}
```

Open the file’s URL in your browser and confirm that it returns the JSON. Replace the example site hostname when entering the feed URL. The `articles` list contains entries; each entry’s `links` list contains its individual Hyper links.

## Create and Map the Feed

In Feed Me, create a feed using that URL and JSON as the feed type. Choose Entries, your News section and its entry type. Select `articles` as the primary element. Follow Feed Me’s [entry-import setup](https://docs.craftcms.com/feed-me/v6/guides/importing-entries.html) for the surrounding feed controls.

Map Title to `title` and Slug to `slug`. For this controlled example, use Slug to match an existing entry on subsequent imports, and enable creating and updating entries. On a real import, choose an identifier that remains unique and stable in its destination section and site.

Expand Resource Links in field mapping. Hyper exposes a **Type** value, native link attributes and custom fields from its configured layouts. Map the values as follows; node labels may display the path with separators in the interface:

| Hyper Mapping | Feed Value |
| --- | --- |
| Type | Set the default value to `url`. |
| Link Value | `links/url` |
| Link Text | `links/text` |
| Summary | `links/summary` |

The Type value selects the enabled link type by its handle. The repeated `links` items create separate links, with each summary kept beside its own destination. Leave unrelated native attributes unmapped for this example.

## Import and Inspect

Save and run the feed. When processing finishes, open Useful Resources in the News section. Resource Links should contain Visit Verbb followed by Craft CMS. Expand each link and check its URL and Summary.

If only one link appears, check **Enable Multiple Links** and the repeated `links` paths. If the summaries are missing, confirm Summary is on the URL link layout and mapped under Resource Links, rather than as an ordinary entry field. Review Feed Me’s import errors if no entry was saved.

To inspect the frontend result, put this in the News entry template:

```twig
<ul>
    {% for link in entry.resourceLinks %}
        {% if link.url %}
            <li>
                {{ link.getLink() }}
                {% if link.summary %}<p>{{ link.summary }}</p>{% endif %}
            </li>
        {% endif %}
    {% endfor %}
</ul>
```

View the entry’s page and confirm that each summary appears under the correct link. Change the first summary in the JSON, rerun the feed, then reload the entry. With the matching strategy configured, the existing entry should update rather than creating another one.

## Other Link Types

For Entry links, map the Type to the enabled Entry handle and provide the destination element ID as Link Value. Confirm that the IDs belong to the intended destination site; arbitrary titles or URLs are not automatically converted to entry IDs by Hyper’s mapper.

An unmapped Hyper field is left alone. Mapped empty values and Feed Me’s empty-value settings affect whether saved links are cleared, so check those settings before applying a feed to populated content. [Creating Links Programmatically](/guides/developers/creating-links-programmatically) covers imports implemented directly in PHP.
