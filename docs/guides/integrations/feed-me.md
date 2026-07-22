# Feed Me

Hyper registers a Feed Me field mapper for importing link content into entries and other elements.

## Setup

1. Install [Feed Me](https://plugins.craftcms.com/feed-me) alongside Hyper.
2. Create or edit a feed that maps to an element type with a Hyper field.
3. In the field mapping UI, expand the Hyper field — each link type and sub-field exposed in the field layout can be mapped from feed data.

Hyper’s Feed Me integration maps nested link attributes (link text, URL suffix, custom fields on the link layout, etc.) according to the field’s configured link types.

## Notes

- Element link values require valid element IDs (or resolvable identifiers supported by your feed mapping).
- Multi-link fields accept multiple mapped link blocks when your feed structure provides them.
- After bulk imports, relation rows in `hyper_links` are synced on element save like normal CP edits.

::: tip
For programmatic link creation outside Feed Me, see [Creating Links Programmatically](/guides/developers/creating-links-programatically).
:::
