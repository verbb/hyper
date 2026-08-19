> [!IMPORTANT]
> **Hyper 3** for **Craft 5** has some breaking changes. Consult our [Upgrading from v2](./docs/get-started/upgrading-from-v2.md) docs for the details.

<p align="center"><img src="https://assets.verbb.io/plugins/hyper/hyper-icon.svg" width="100" height="100" alt="Hyper icon"></p>
<h1 align="center">Hyper for Craft CMS</h1>

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

## Link Types

- Asset
- Calendar Event (for [Calendar](https://plugins.craftcms.com/calendar))
- Category
- Custom
- Email
- Embed (oEmbed)
- Entry
- Formie Form (for [Formie](https://plugins.craftcms.com/formie))
- Passive
- Phone
- Product (for [Commerce](https://plugins.craftcms.com/commerce))
- Shopify Product (for [Shopify](https://plugins.craftcms.com/shopify))
- Site
- URL
- User
- Variant (for [Commerce](https://plugins.craftcms.com/commerce))

## Documentation

Visit the [Hyper Plugin page](https://verbb.io/craft-plugins/hyper) for all documentation, guides, pricing and developer resources.

## Support

Get in touch with us via the [Hyper Support page](https://verbb.io/craft-plugins/hyper/support) or by [creating a Github issue](https://github.com/verbb/hyper/issues)

<h2></h2>

<a href="https://verbb.io" target="_blank">
    <img width="101" height="33" src="https://verbb.io/assets/img/verbb-pill.svg" alt="Verbb">
</a>
