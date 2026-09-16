# Element Links

Use an element link when an editor should choose existing Craft content, such as an entry or asset, instead of typing its URL. Hyper resolves the selected element’s URL when rendering the link. An Entry link can also use the entry’s title when the editor leaves Link Text empty and no layout default is set.

## Choose Available Content

In the Hyper field’s link type settings, select Entry and choose its **Sources**. For a related-articles field, restrict the sources to News so editors can find the relevant entries without searching unrelated sections. If the field uses a shared config, make this change in [Link Type Configs](/feature-tour/link-type-configs).

Entry, Asset and User types also provide selectable-element conditions. Sources choose where editors can look; conditions narrow the choices within those sources. See [Link Type Settings](/reference/link-type-settings#selectable-element-conditions).

## URL & Text

Suppose your entry has a single-link Hyper field with the handle `myLinkField`. In its entry template, use:

```twig
{{ entry.myLinkField.getLink() }}
```

For custom markup, read `entry.myLinkField.url` and `entry.myLinkField.text`. These resolve through the linked element where needed. Hyper can load targets together, but reading URL or text is not a guarantee of zero database queries. [Eager Loading](/feature-tour/eager-loading) explains how to load additional content efficiently.

## Linked Element

Use `getElement()` when you need the selected element itself. For example, to display an Entry link’s target title independently of the editor’s link label:

```twig
{% set linkedEntry = entry.myLinkField.getElement() %}
{% if linkedEntry %}
    <h2>{{ linkedEntry.title }}</h2>
{% endif %}
```

The link’s own `title` property is its HTML title attribute. It is not the selected entry’s title.

Element lookups can return nothing if the selected target is unavailable. Entry links normally resolve live entries, taking publication and expiry dates into account. Guard access to target fields, and use `link.url` when deciding whether you can display an anchor. `isEmpty()` describes stored content and can be false even when a link has no usable destination.

## Custom Fields

If a card needs a thumbnail from its linked entry, load that field with the entries rather than querying it separately for each card. Add `myLinkField.linkedElements.thumbnail` to the owner query’s `with()` paths. The [Eager Loading example](/feature-tour/eager-loading#load-thumbnails-for-linked-entries) includes a complete query and output.

For translated content and links selected from another site, see [Working with Multiple Sites](/feature-tour/working-with-multiple-sites).
