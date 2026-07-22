# Link

In templates you work with a hydrated **Link** object — a read-time view of one saved link row. Serialized content is stored as a `LinkInstance` DTO on the owner element; Hyper hydrates it through `Links::createLinkFromInstance()`.

For the field value wrapper (single vs multi-link), see [LinkCollection](/reference/link-collection).

## Architecture

Hyper separates **link type definitions** (field settings) from **link instances** (saved content):

| Layer | Class | Role |
| --- | --- | --- |
| **Definition** | `LinkTypeDefinition` | One row in the Hyper field’s link type settings (handle, layout, enabled) |
| **Settings owner** | `LinkTypeSettings` | CP-only Element used as the field layout owner when designing link types |
| **Registry** | `Link` subclasses (`Url`, `Entry`, …) | Type behaviour: URL resolution, element queries, empty rules |
| **Content** | `LinkInstance` | Serialized JSON on the owner element — `linkTypeHandle`, `linkValue`, attributes, custom fields |
| **Field value** | `LinkCollection` | Iterable field value; hydrates `Link` objects for templates |

Saved content never stores a Craft Element row per link. Link type settings (`label`, `sources`, layout config) live on the field definition — see [Link Type Settings](/reference/link-type-settings).

Saved content uses **`linkTypeHandle`** to identify the link type. Content JSON stores the handle, link value, attributes, and custom field values keyed by layout element UID.

## Hydrated link (template object)

### Attributes

Attribute | Description
--- | ---
`type` | Link type class name, e.g. `verbb\hyper\links\Entry`.
`linkType` | Settings prototype for this link’s handle.
`url` | Resolved `href` value. Supports `.env` variables, aliases, prefix/suffix.
`text` | Derived link label. Element links use the linked element’s title when Link Text is empty (after layout defaults).
`target` | Returns `_blank` when the link opens in a new window.
`newWindow` | Whether the link opens in a new window.
`linkUrl` | Raw link URL before full resolution.
`linkUri` | URI segment for element-based links.
`linkValue` | Type-specific stored value (string, element id, etc.).
`linkText` | Resolved label before `text` applies fallbacks.
`customLinkText` | Author-entered Link Text only — `null` when blank. Use `text` for the full derived label.
`ariaLabel` | Value for `aria-label`.
`urlSuffix` | Suffix appended to the URL (`?query`, `#fragment`).
`linkTitle` | Value for `title`.
`classes` | Value for `class`.
`customAttributes` | Custom HTML attributes.

### Methods

Method | Description
--- | ---
`getElement(status)` | Linked element for element-based types. Default status: live only.
`hasElement(status)` | Whether an element is linked.
`getLink(attributes)` | Render an `<a>` tag. Pass attributes to override defaults.
`getLinkAttributes(attributes, asString)` | HTML attributes for the anchor.
`getCustomLinkText()` | Link Text field value only (`null` when blank).

## Element link

Element links extend the base link object (Entry, Category, Asset, etc.).

### Attributes

Attribute | Description
--- | ---
`linkSiteId` | Site ID of the linked element.

### Methods

Method | Description
--- | ---
`getElement(status)` | The linked Craft element.
`hasElement(status)` | Whether a target element is set.

Element links resolve URL and text from batch-primed elements and `hyper_links` relation rows. See [Element Links](/feature-tour/element-links) and [Eager Loading](/feature-tour/eager-loading).

## Developers

```php
// Hydrate content
Hyper::$plugin->getLinks()->createLinkFromInstance($field, $instance);

// Settings prototype
Hyper::$plugin->getLinks()->createSettingsPrototype($config);

// Field definitions
$field->getLinkTypeDefinitions();
$field->getLinkTypeSettingsOwners();
```

See [Link Types](/developers/link-types) for custom link type registration.
