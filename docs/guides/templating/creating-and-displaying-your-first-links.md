# Creating and Displaying Your First Links

In this guide, you’ll add a resource link to an entry and display it on your website. You’ll then turn the field into a list, with a heading that is text rather than a clickable destination.

You need Hyper installed, an administrator account in a development environment that allows settings changes, and access to your site’s Twig templates. Start with an existing entry type and a published entry whose frontend template you can edit. If you do not have that starting point, ask your site developer to identify the entry type and its template before continuing. [Installation & Setup](/get-started/installation-setup) covers installing Hyper.

## Create the Resource Field

Open **Settings → Fields → New Field**. Name the field `Resource Links`, set its handle to `resourceLinks` and choose **Hyper** as its type. A handle is the name you use in code to refer to the field; it does not need to match the label shown to editors.

Choose **Custom** under **Link Type Config** so this example’s choices apply only to this field. Enable URL and Passive in **Link Types**, and disable the other types. Set **Default Link Type** to URL. Leave **Enable Multiple Links** off for the first part of the guide, then save the field.

URL gives editors an address and label. Passive provides a label without an address; we’ll use it for a list heading later.

## Put the Field on an Entry

Open **Settings → Entry Types** and select the entry type used by your example entry. Add Resource Links to its field layout and save. Open your example entry in **Entries**. You should see Resource Links on the tab where you placed it.

Add a URL link. Enter `https://verbb.io` for the destination and `Visit Verbb` for **Link Text**, then save the entry. If the field does not appear, check that you edited the entry type used by this entry and saved the layout.

## Display the Link

Open the Twig template used by that entry. Add this where you want the resource link to appear, inside the template’s existing content block if it extends a layout:

```twig
{{ entry.resourceLinks.getLink() }}
```

The template’s `entry` variable represents the entry being viewed. `resourceLinks` selects your field, and `getLink()` builds its anchor tag. With the sample values and the new-window option off, it produces:

```html
<a href="https://verbb.io">Visit Verbb</a>
```

View the published entry’s page in your browser and follow the link. You should reach the Verbb website. Return to the editor, change Link Text to `Explore Verbb`, save and refresh the frontend page. The label should change without editing the template.

If nothing appears, confirm that the entry is saved, you are viewing the correct site and entry, and the template uses the exact field handle `resourceLinks`.

## Turn It Into a Resource List

Return to the field’s settings, enable **Enable Multiple Links** and save. Reopen the entry and add a second URL link to `https://craftcms.com`, with Link Text `Craft CMS`.

Add a Passive link with Link Text `Useful Websites` and move it above the two URL links. Save the entry. A Passive item has no destination, so `getLink()` alone cannot display it.

Replace the earlier single-line Twig example with this complete list:

```twig
{% if not entry.resourceLinks.isEmpty() %}
    <ul class="resource-links">
        {% for link in entry.resourceLinks %}
            {% if link.url %}
                <li>{{ link.getLink() }}</li>
            {% elseif link.type == 'verbb\\hyper\\links\\Passive' and link.text %}
                <li><span>{{ link.text }}</span></li>
            {% endif %}
        {% endfor %}
    </ul>
{% endif %}
```

The loop visits each link in the order you saved. Links with destinations render as anchors. The Passive item renders as a span, so “Useful Websites” appears as text. The outer check omits the list when the field has no meaningful content.

## Check the Finished List

Refresh the page. You should see Useful Websites followed by Visit Verbb or your edited label, then Craft CMS. The heading should not be clickable, and each URL link should open its own destination.

Reorder the URL links, save and refresh to confirm the template follows the editor’s order. On your development entry, remove all links and save once more: the list should disappear without an error. Add them back if you want to keep the example.

You can use [Rendering Links](/feature-tour/rendering-links) to add classes and customise labels, or [Custom Fields Inside Links](/feature-tour/custom-fields-inside-links) to give each resource a summary. The field and template already work together before those additions.
