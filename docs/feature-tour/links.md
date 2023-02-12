# Links
To begin, navigate to **Settings** → **Fields** → **New Field** to create a new field in Craft. Select **Hyper** as the field type.

## Link Settings
The settings for a Hyper field should be reasonably self-explanatory.

- Default Link Type - Select the default link type when the element is created.
- Enable New Window - Whether to show the "Open in New Window" option for links.
- Enable Multiple Links - Whether users can create multiple links in a single field, or just a single link.

### Multiple Links
Hyper allows you to create multiple links in a single field. This gives you greater flexibility when you need to create 2 or more links without having to create multiple link fields, or combine with Matrix or Super Table fields. More importantly, multiple links have no performance hits, unlike using with the aforementioned options.

### Link Types
You can define which link types are available to the field. You can disable any registered link type, along with renaming it and even changing the order shown in. Each link type will have a different set of options, but element-based link types will allow you to select which sources are allowed.

You can also create multiple instanced of link types. For example, you might like to disable the default **Entry** link type, and create a new Entry link type called **Blog** which allows only your Blog section to be enabled. You might create another link type to do the same thing for your Testimonials section. Through this you can have fine-grain control over different link types.

#### Link Fields
Each link type lets you define a field layout, to include native fields, custom fields or UI elements, shown to editors when creating links.

Native fields (which are built-in to Hyper) are:

- Aria Label (for the `aria-label` HTML attribute)
- Classes (for the `class` HTML attribute)
- Custom Attributes (for any HTML attribute)
- Link (for the link type specific setting)
- Link Text (the text for the `<a>` anchor tag)
- Link Title (for the `title` HTML attribute)
- Url Suffix (to append a value like `?success=true` or `#section-start` to the URL)

You can include any custom field you like, or UI elements. Fields placed in the first tab will be shown to the user immediately when editing an element. Any other tabs will be considered secondary content and be hidden in a slide-out panel for the user to manage.

## Link Input
Once your field is created, add it to the appropriate entry type (or other element), and edit the entry.

The UI for a Hyper field will show the default link type with all fields in the first tab of your field layout. A settings icon will open a slide-out pane for all other fields. Users can populate the field with content.

For multi-link fields, you'll be able to create new link blocks, re-order them and delete them.

## Rendering a Link
With everything in place, you can now render a link on the front-end of your site

```twig
{{ entry.myLinkField.getLink() }}
```

:::tip
Check out our guide on [Rendering Links](docs:template-guides/rendering-links) for more.
:::
