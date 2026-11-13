# Link

A Link represents one destination and its saved label, attributes and custom fields. Obtain one with `entry.myLinkField.first()` or by looping over a Hyper field. `first()` can return null, and the field itself is a [LinkCollection](/reference/link-collection).

[Rendering Links](/feature-tour/rendering-links) provides template examples. This page describes the individual object and its saved content.

<span id="attributes"></span>

## Properties

::: reference
### `type`

**Type:** `string`

The link type’s class name, such as `verbb\hyper\links\Entry`. [Built-in Classes](#built-in-classes) lists the classes Hyper includes.
:::

::: reference
### `linkType`

**Type:** `verbb\hyper\base\LinkInterface|null`

The link type object that supplies settings for this link.
:::

::: reference
### `url`

**Type:** `string|null`

The resolved destination, after Hyper applies the URL prefix, suffix and URL checks. This is null when the destination is absent or unavailable, and a suffix alone doesn’t create a destination. Stored destinations are literal text, so environment variables and Craft aliases aren’t expanded.
:::

::: reference
### `text`

**Type:** `string|null`

The label to show, using the entered Link Text, then layout defaults and type-specific fallbacks such as an entry’s title. Ordinary links return null without a usable URL, while Passive links can return a label without one.
:::

::: reference
### `target`

**Type:** `string|null`

The `target` attribute, which is `_blank` when the link opens in a new window and null otherwise.
:::

::: reference
### `newWindow`

**Type:** `bool|null`

Whether the editor chose to open the link in a new window, or null when no choice was saved. `target` uses the resolved choice, including any field default.
:::

::: reference
### `linkUrl`

**Type:** `string|null`

The type-specific destination before Hyper adds the URL prefix and suffix.
:::

::: reference
### `linkUri`

**Type:** `string|null`

The URI of the linked element, or null when the link has no element or the element has no URI.
:::

::: reference
### `linkValue`

**Type:** `mixed`

The value stored for the link type, such as a URL string, an element ID, a site UID or embed metadata.
:::

::: reference
### `linkText`

**Type:** `string|null`

The Link Text, falling back to layout defaults and type-specific values such as an element’s title.
:::

::: reference
### `customLinkText`

**Type:** `string|null`

The Link Text an editor entered, with no fallbacks. This is null when the field is blank.
:::

::: reference
### `ariaLabel`

**Type:** `string|null`

The value for the link’s `aria-label` attribute.
:::

::: reference
### `urlSuffix`

**Type:** `string|null`

The suffix added to the URL, such as a query string or fragment.
:::

::: reference
### `linkTitle`

**Type:** `string|null`

The value for the link’s `title` attribute, which you can also read as `title`. This isn’t the title of a selected entry.
:::

::: reference
### `classes`

**Type:** `string|null`

The value for the link’s `class` attribute.
:::

::: reference
### `customAttributes`

**Type:** `array`

The extra HTML attributes for the link, as name and value pairs. Hyper validates the attribute names.
:::


Custom layout fields are accessible by their handles. Values on the selected destination are accessed through `getElement()` instead.

## Methods

::: reference
### `getElement()`

**Arguments:** `$status` (`mixed`, optional), the element status to require.

**Returns:** `craft\base\ElementInterface|null`

Returns the linked Craft element, or null when there isn’t one or it doesn’t match the status. Element links require an enabled element by default, and Entry links require a live entry.
:::

::: reference
### `hasElement()`

**Arguments:** `$status` (`mixed`, optional), the element status to require.

**Returns:** `bool`

Returns whether the linked element can be loaded with the requested status. Links that aren’t element links always return `false`.
:::

::: reference
### `getLink()`

**Arguments:** `$attributes` (`array`, optional), extra attributes for the anchor.

**Returns:** `Twig\Markup|null`

Returns the link as an `<a>` element, or null when it has no usable URL. The special `text` key overrides its label. Ordinary strings are escaped, and trusted Twig markup is kept as it is.

Passed classes are added to saved classes. Other passed attributes override saved values with the same name.
:::

::: reference
### `getLinkAttributes()`

**Arguments:** `$attributes` (`array`, optional), extra attributes to merge in, and `$asString` (`bool`, defaults to `false`).

**Returns:** `Twig\Markup|array`

Returns the link’s attributes as an array, or as Twig markup containing the attribute string when `$asString` is `true`. Use it to build your own anchor or button.
:::

::: reference
### `getCustomLinkText()`

**Returns:** `string|null`

Returns the Link Text an editor entered, with no fallbacks, or null when it’s blank.
:::

::: reference
### `isEmpty()`

**Returns:** `bool`

Returns whether the link has no usable URL. Labels, attributes and custom fields don’t count as a destination, so unavailable targets and Passive links are empty, although their stored content is kept.
:::


Element links also expose `linkSiteId`, the selected destination’s site ID. For efficient access to their fields, see [Loading Links](/reference/loading-links).

## Embed Links

::: reference
### `getHtml()`

**Returns:** `Twig\Markup|null`

Returns the stored embed HTML as Twig markup, or null when the link has no embed HTML.
:::

::: reference
### `getIframeSrc()`

**Returns:** `string|null`

Returns the `src` of the first iframe in the embed HTML, or null when there isn’t one.
:::

::: reference
### `getEmbedImage()`

**Returns:** `string|null`

Returns the embed’s thumbnail or image URL, or null when the embed has none.
:::

::: reference
### `getEmbedProviderName()`

**Returns:** `string|null`

Returns the embed provider’s name, such as YouTube, or null when the link has no embed.
:::

::: reference
### `getData()`

**Returns:** `array|null`

Returns all the stored embed metadata for an Embed link, or null when there is none. Only Embed links have this method.
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
