# Developers

Creating fields and links in PHP, plus performance patterns for element queries.

##### [Creating Links Programmatically](/guides/developers/creating-links-programatically)

Create Link objects and assign them to Hyper fields when building custom integrations or element saves. Always pass an array of links.

##### [Creating Hyper Fields Programmatically](/guides/developers/creating-hyper-fields)

Seed Hyper fields in content migrations with the correct `linkTypes` array shape (`type`, `handle`, `label`).

##### [Performance](/guides/developers/performance)

Batch hydration, `with(['field.linkedElements…'])`, and `craft.hyper.getRelatedElements()` — checklist linking to eager loading and reverse relations.
