# Hyper

Hyper gives editors a field for adding links to entries, assets, external websites, and other destinations. You choose which kinds of links the field accepts, whether it holds one link or several, and what extra information editors can provide for each link.

## Creating and Displaying Links

Start with [Installation & Setup](/get-started/installation-setup), then create a field using the [Overview](/feature-tour/overview). [Link Type Configs](/feature-tour/link-type-configs) explains how to share link settings across fields.

Once an editor has saved a link, follow [Rendering Links](/feature-tour/rendering-links) to display it in Twig. For example, a page could have a single call-to-action link or a list of related resources. [Element Links](/feature-tour/element-links) explains how links to Craft content resolve their destinations.

## Working with Linked Content

[Eager Loading](/feature-tour/eager-loading) explains how to load linked content together when displaying several links. [Reverse Relations](/feature-tour/reverse-relations) helps you find entries that link to a particular element. [Checking Link Performance](/guides/developers/checking-link-performance) shows how to compare queries on a working page.

For a separate frontend, use [GraphQL](/developers/graphql) to retrieve link data. To bring content into Craft, follow the [Feed Me guide](/guides/integrations/importing-links-with-feed-me).

## Extending Hyper

You can [create links in PHP](/guides/developers/creating-links-programmatically), [create Hyper fields in a migration](/guides/developers/creating-hyper-fields-programmatically), or add a custom destination with [Link Types](/developers/creating-link-types).

The [Link](/reference/link), [LinkCollection](/reference/link-collection), and [Link Type Settings](/reference/link-type-settings) pages explain the objects and settings used by those examples. Start with [Creating and Displaying Your First Links](/guides/templating/creating-and-displaying-your-first-links) for a complete walkthrough.

## Moving an Existing Site

Use [Migrations](/guides/migrations-upgrades/) when replacing another link plugin. If the site already uses Hyper, read [Upgrading from v2](/get-started/upgrading-from-v2) for the changes to review.
