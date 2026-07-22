# Overview

To begin, navigate to **Settings** → **Fields** → **New Field** to create a new field in Craft. Select **Hyper** as the field type.

## Field settings

![Hyper field settings with link types and URL field layout](/_screenshots/feature-tour/overview-field-settings.png)

- **Default Link Type** — link type selected when a new link is created.
- **Enable New Window in Header** — show the “Open in New Window” header icon (or a layout lightswitch when the **New Window** native field is added to a link type).
- **Enable Multiple Links** — allow one or many links in the same field.

### Multiple links

Hyper supports multiple links in a single field without Matrix or Super Table overhead. Each link is a separate row in field content with no per-link performance penalty compared to using multiple Hyper fields.

When multiple links are enabled, the field value is a [LinkCollection](/reference/link-collection). When disabled, the collection delegates to the first link for convenience (`entry.myLinkField.url`).

### Link types

Enable, rename, and reorder link types per field. Element-based types let you restrict **sources** (sections, volumes, etc.). You can add multiple instances of the same base type — for example, separate Entry types scoped to Blog vs Testimonials.

Link type definitions, native layout fields, and attribute mapping are documented in [Link Type Settings](/reference/link-type-settings).

### Link Type Defaults

For shared link types across many Hyper fields, use [Link Type Configs](/feature-tour/link-type-configs). New fields use the Default config; choose **Custom** when you need a unique suite.

## Link input

![Multi-link Hyper field with URL and Entry rows](/_screenshots/feature-tour/overview-link-input.png)

Add the field to an entry type (or other element) and edit an entry. The default link type shows layout fields inline. When a link type has more than one field-layout tab, those tabs appear on the block header (Content / Advanced, etc.) — the same pattern as Matrix, Neo, and Vizy. Multi-link fields support add, reorder, and delete.

![Hyper link block with inline Content and Advanced tabs](/_screenshots/feature-tour/overview-link-tabs.png)

### Copy, cut, and paste

Each link’s ⋯ menu includes **Copy**, **Cut**, and **Paste** (Neo-style). Copied links live in the browser `localStorage` clipboard and can be pasted into another Hyper field — including on a different entry — when that field has a compatible link type enabled.

- **Copy** — keeps the source link
- **Cut** — copies then removes the source
- **Paste** — available on the block menu and on the Add control when the clipboard has a compatible link

Custom layout fields remap by **handle**. Link types match by class (FQCN). Each link also stores a durable `uid` in content JSON so identity survives cut/paste.

## Rendering

```twig
{{ entry.myLinkField.getLink() }}
```

::: tip
See [Rendering Links](/feature-tour/rendering-links) for URL, text, element access, and multi-link patterns.
:::
