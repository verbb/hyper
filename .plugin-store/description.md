Hyper is a Craft CMS plugin for creating links, with a focus on user experience and flexibility.

## What's new in Hyper 3

- **Updated Field UI** — [Plugin Kit](https://docs.verbb.io/plugin-kit/overview/) web components, blocks/cards view modes, inline layout tabs to replace slide-out, and compact link headers.
- **Link Type Configs** — Named, reusable link type suites in project config; fields pick a shared config or Custom.
- **Performance Improvements** — `hyper_links` replaces the element cache for FK integrity, reverse lookups, and always-on priming (including nested Matrix/Neo owners).
- **Eager Loading & Reverse Relations** — Craft-style `with(['field.linkedElements…'])` and `craft.hyper.getRelatedElements()`.
- **Bulk Add** — Opt-in multi-link creation from element pickers or one-value-per-line text (URL / Email / Phone).
- **Copy / Cut / Paste** — Clipboard for moving links across entries and fields.
- **Conditions** — Selectable-element conditions on Entry, Asset, and User; field-layout visibility/editability for every link type.
- **Passive Links** — Label-only links with no URL target.
- **Stable Handles & GraphQL** — Short type keys (`url`, `entry`, …), cleaner GraphQL type names, and a `fields` JSON bag on the link interface.
- **New Migrations** — Craft 5.3+ native Link, [oEmbed](https://plugins.craftcms.com/oembed), and Category→Entry entrification.

## Features

- Create single or multiple links in one field
- Link to elements (Entries, Categories, Assets, Users, and more), URLs, Email, Phone, Sites, Embeds, or Passive labels
- Built-in (optional) fields for link text, title, suffix, Aria label, classes, attributes, and New Window
- Add any custom field to a link type — text, icons, even Matrix
- Named Link Type Configs, or per-field Custom link type suites
- Enable or disable the link types you need for a given field
- Customise each link type’s field layout
- Selectable-element and field-layout conditions for authoring control
- Batch hydration, eager loading, and reverse relations for element links
- Plenty of template helpers to make rendering links a breeze
- GraphQL support for querying links
- [Feed Me](https://plugins.craftcms.com/feed-me) support for importing links
- Migrations from [Typed Link Field](https://plugins.craftcms.com/typedlinkfield), [Linkit](https://plugins.craftcms.com/linkit), [Link](https://plugins.craftcms.com/link), Craft Link, and [oEmbed](https://plugins.craftcms.com/oembed)
- Events to write your own link types, or extend existing ones

### Link types

- **Asset** — link to an asset.
- **Calendar Event** — link to a [Calendar](https://plugins.craftcms.com/calendar) event.
- **Category** — link to a category.
- **Custom** — custom URL with flexible settings.
- **Email** — `mailto:` links.
- **Embed** — oEmbed social / media embeds with domain allowlists.
- **Entry** — link to a section entry.
- **Formie Form** — link to a [Formie](https://plugins.craftcms.com/formie) form.
- **Passive** — label-only with no URL target.
- **Phone** — `tel:` links.
- **Product** — link to a [Commerce](https://plugins.craftcms.com/commerce) product.
- **Shopify Product** — link to a [Shopify](https://plugins.craftcms.com/shopify) product.
- **Site** — link to another site in a multi-site install.
- **URL** — standard web URLs (including hash-only and custom schemes).
- **User** — link to a user.
- **Variant** — link to a [Commerce](https://plugins.craftcms.com/commerce) variant.

### Build & manage

- Single- and multi-link fields with Blocks or Cards view modes.
- Per-link-type field layouts with Content / Advanced tabs.
- Bulk Add for seeding many links at once from elements or text.
- Copy / Cut / Paste between fields and entries.
- Default and fixed URL values, Link Text defaults, and character limits.
- Conditions for which elements can be selected, and which layout fields show or stay editable.

### Templates & front end

- Render helpers for `url`, `text`, attributes, and full link markup.
- Always-on batch priming for element links on site requests.
- Explicit `with(['myLinkField.linkedElements…'])` for fields on linked elements.
- `craft.hyper.getRelatedElements()` for reverse “pages linking here” lookups.
- GraphQL link interface with typed fragments and a `fields` JSON bag.

### Migrations

Import fields and content from other Craft link plugins. Source plugin data is never modified.

- [Typed Link Field](https://plugins.craftcms.com/typedlinkfield)
- [Linkit](https://plugins.craftcms.com/linkit)
- [Link](https://plugins.craftcms.com/link) (flipbox)
- Craft CMS native Link field (5.3+)
- [oEmbed](https://plugins.craftcms.com/oembed)

Run migrations from **Hyper → Settings**, or via console commands (`php craft hyper/migrate/typed-link`, etc.).

## Documentation

Visit the [Hyper Plugin page](https://verbb.io/craft-plugins/hyper) for all documentation, guides, pricing and developer resources.

## Support

Get in touch with us via the [Hyper Support page](https://verbb.io/craft-plugins/hyper/support) or by [creating a Github issue](https://github.com/verbb/hyper/issues)
