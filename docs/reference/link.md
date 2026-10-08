# Link

A Link represents one destination and its saved label, attributes and custom fields. Obtain one with `entry.myLinkField.first()` or by looping over a Hyper field. `first()` can return null; the field itself is a [LinkCollection](/reference/link-collection).

[Rendering Links](/feature-tour/rendering-links) provides template examples. This page describes the individual object and its saved content.

<span id="attributes"></span>

## Properties

::: reference
### `type`

**Type:** `string`

Link class name, such as `verbb\hyper\links\Entry`.
:::

::: reference
### `linkType`

**Type:** `verbb\hyper\base\LinkInterface|null`

The link type object that supplies settings for this link.
:::

::: reference
### `url`

**Type:** `string|null`

Resolved destination after applying the prefix, suffix and URL-policy checks. Returns null when the destination is absent or unavailable; a suffix alone does not create a destination. Stored destinations are literal text; environment-variable references and Craft aliases are not expanded.
:::

::: reference
### `text`

**Type:** `string|null`

Display label using entered text, layout defaults and type-specific fallbacks. Ordinary links return null without a usable URL. Passive links can return a label without a URL.
:::

::: reference
### `target`

**Type:** `string|null`

`_blank` when the link opens in a new window; otherwise null.
:::

::: reference
### `newWindow`

**Type:** `bool|null`

Saved new-window choice; `target` uses the resolved choice including applicable field defaults.
:::

::: reference
### `linkUrl`

**Type:** `string|null`

Type-specific destination before prefix and suffix.
:::

::: reference
### `linkUri`

**Type:** `string|null`

URI of the resolved element, if available.
:::

::: reference
### `linkValue`

**Type:** `mixed`

Type-specific stored value: a URL string, element ID, site UID or embed metadata.
:::

::: reference
### `linkText`

**Type:** `string|null`

Link Text with layout defaults and type-specific fallbacks.
:::

::: reference
### `customLinkText`

**Type:** `string|null`

Only the editor-entered Link Text; null when blank.
:::

::: reference
### `ariaLabel`

**Type:** `string|null`

HTML `aria-label` value.
:::

::: reference
### `urlSuffix`

**Type:** `string|null`

Suffix such as a query string or fragment.
:::

::: reference
### `linkTitle`

**Type:** `string|null`, `title`

HTML `title` value, not the title of a selected entry.
:::

::: reference
### `classes`

**Type:** `string|null`

HTML `class` value.
:::

::: reference
### `customAttributes`

**Type:** `array`

Additional HTML attribute name/value pairs, subject to attribute-name validation.
:::


Custom layout fields are accessible by their handles. Values on the selected destination are accessed through `getElement()` instead.

## Methods

::: reference
### `getElement($status)`

**Returns:** `craft\base\ElementInterface|null`

Selected Craft element or null. Entry links default to live entries; other element types use their applicable enabled status.
:::

::: reference
### `hasElement($status)`

**Returns:** `bool`

Whether the selected element can be resolved with the requested status.
:::

::: reference
### `getLink(array $attributes = [])`

**Returns:** `Twig\Markup|null`

Twig markup for an anchor, or null without a usable URL. The special `text` key overrides its label. Ordinary strings are escaped; trusted Twig markup is preserved.
:::

::: reference
### `getLinkAttributes(array $attributes = [], bool $asString = false)`

**Returns:** `Twig\Markup|array`

Attribute array, or Twig markup containing the attribute string when `asString` is true.
:::

::: reference
### `getCustomLinkText()`

**Returns:** `string|null`

Editor-entered text only, or null when blank.
:::

::: reference
### `isEmpty()`

**Returns:** `bool`

Whether the link resolves to no non-blank URL. Labels, attributes and custom fields do not make a destination-less link non-empty. Unavailable targets and Passive links are empty; stored content is still retained.
:::


Element links also expose `linkSiteId`, the selected destination’s site ID. For efficient access to their fields, see [Loading Links](/reference/loading-links).

## Embed Links

::: reference
### `getHtml()`

**Returns:** `Twig\Markup|null`

Stored embed HTML as Twig markup, or null.
:::

::: reference
### `getIframeSrc()`

**Returns:** `string|null`

First iframe source in stored embed HTML, or null.
:::

::: reference
### `getEmbedImage()`

**Returns:** `string|null`

Stored thumbnail/image URL, or null.
:::

::: reference
### `getEmbedProviderName()`

**Returns:** `string|null`

Stored provider name, or null.
:::

::: reference
### `getData()`

**Returns:** `array|null`

Available on Embed links.

Embed metadata on an Embed link.
:::


For example, a URL link’s `linkValue` is an address string, an Entry link identifies an element, and an Embed link can store an object containing `url`, `title`, `code` and other provider metadata. Available embed keys depend on the fetched result.

## Saved Content

Hyper stores each link’s content on its owner rather than saving a Craft element row for each link. A `LinkInstance` carries supported values while `Links::createLinkFromInstance($field, $instance)` creates the runtime Link object using its configured type and layout.

The supported content includes `linkTypeHandle`, `uid`, `linkValue`, `linkSiteId`, `newWindow`, `linkText`, `ariaLabel`, `urlSuffix`, `linkTitle`, `classes`, `customAttributes` and `fields`. Empty optional values may be omitted. Custom field values are stored by their layout placement UID; normal programmatic input can supply them by handle.

Use `linkTypeHandle` when selecting an exact configured type in a content array. Input also accepts `handle`, or `type` containing a registered class name or built-in type key. Keep field settings separate from saved link values; each saved link only needs to identify its configured type.

For conversions of raw values, use [Managing Embedded Content](/developers/managing-embedded-content). For normal element saves, follow [Creating Links Programmatically](/guides/developers/creating-links-programmatically).

## Built-in Classes

All names below are in the `verbb\hyper\links` namespace. Types requiring another plugin are available only when their dependency is enabled.

| Class | Destination |
| --- | --- |
| `Asset` | Craft asset. |
| `CalendarEvent` | Calendar event. |
| `Category` | Craft category. |
| `Custom` | Custom address subject to the shared URI policy. |
| `Email` | Email address. |
| `Embed` | Remote URL and its embed metadata. |
| `Entry` | Craft entry. |
| `FormieForm` | Formie form. |
| `Passive` | Label without a destination URL. |
| `Phone` | Phone number. |
| `Product` | Commerce product. |
| `ShopifyProduct` | Shopify product. |
| `Site` | Craft site. |
| `Url` | Relative or absolute address. |
| `User` | Craft user. |
| `Variant` | Commerce variant. |
