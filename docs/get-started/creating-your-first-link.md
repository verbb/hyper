# Creating Your First Link

Start with Hyper installed and an entry whose Twig template you can edit. You'll add one resource link to that entry and display it on the site.

Open **Settings → Fields → New Field**, choose **Hyper**, and use the name Resource Links and handle `resourceLinks`. Leave multiple links disabled for this example and make sure URL is an available link type. Save the field and add it to the entry type's field layout.

Open the entry, add a URL link to `https://verbb.io`, and enter `Visit Verbb` for **Link Text**. Save the entry. In its Twig template, place this where the link should appear:

```twig
{{ entry.resourceLinks.getLink() }}
```

Visit the entry's public page and follow the link. Change its text in the editor, save and refresh the page to confirm the field supplies the label as well as the destination.

The longer [Creating and Displaying Your First Links](docs:guides/templating/creating-and-displaying-your-first-links) guide continues this example with multiple links and a passive heading. Use it when you want the full walkthrough and explanations of each field setting.
