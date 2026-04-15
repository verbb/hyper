# Link
Whenever you're dealing with a link in your template, you're actually working with a `Link` object.

Link objects can be classified into two parts, the Link Type and the Link, which represent the settings and value respectively. All attributes and methods are available to you in templates, but you'll likely only need **Link** data for the front-end.

## Link Type
The Link Type represents the chosen type of link you want the link to be.

### Attributes

Attribute | Description
--- | ---
`label` | The label for the link type.
`handle` | The handle for the link type. Automatically generated and cannot be changed.
`enabled` | Whether the link type is enabled or not.
`isCustom` | Whether this is a custom-created link type, or a default one.

### Methods

Method | Description
--- | ---
`getSettingsConfig()` | An array of variables for this link type, used in the field settings template and saved to the database.
`getInputConfig()` | An array of variables for this link type, used in the field input Vue component.
`getSerializedValues()` | An array of variables to save as the value of a link (its content) to the database.
`getFieldLayout()` | Returns the field layout for fields and UI elements.

## Element Link Type
An Element Link is an extension of a regular Link object, and is inherited by all element-base link types like an Entry, Category, etc.

### Attributes

Attribute | Description
--- | ---
`sources` | The allowed sources for users to pick elements from.
`selectionLabel` | The text used for the **Choose** button to select an element.


## Link
The Link represents the actual value of the field, as you'd want to output in your template.

### Attributes

Attribute | Description
--- | ---
`type` | Returns the link type class name chosen for the link. e.g. `verbb\hyper\links\Entry`.
`linkType` | Returns the [link type](docs:developers/link-type) object chosen for the link. 
`url` | The value used for the `href` for the link. Supports .env variables and aliases, and combines any prefix or suffix.
`text` | The derived text for the label of the link. If an element link type, the title of the element will be used automatically when the Link Text field is empty (after considering the field layout placeholder and plugin defaults).
`target` | Returns `_blank` if the link should open in a new window.
`newWindow` | Whether the link should open in a new window.
`linkUrl` | The link URL. Supports `.env` variables and aliases.
`linkUri` | The link URI, if an element-based link.
`linkValue` | The value of the link. This will vary depending on the link type.
`linkText` | The resolved link label before `text` applies layout defaults: custom Link Text if set; otherwise, for element-based links, the linked element’s title (or string representation). For **only** what was typed in the Link Text field (so you can use Twig `??` fallbacks), use `customLinkText`.
`customLinkText` | Only the author-entered value from the Link Text field. `null` when that field is left blank—no element title or other fallback. Use `text` for the full derived label.
`ariaLabel` | The value for the `aria-label` attribute for the link.
`urlSuffix` | The suffix value to append to the URL.
`linkTitle` | The value for the `title` attribute for the link.
`classes` | The value for the `class` attribute for the link.
`customAttributes` | Any custom HTML attributes for the link.

### Methods

Method | Description
--- | ---
`getElement(status)` | Returns the linked element if an element-based link type. `status` can be supplied to filter based on the status (by default, only live elements will be returned).
`hasElement(status)` | Returns whether linked to an element, or an element-based link type. `status` can be supplied to filter based on the status (by default, only live elements will be returned).
`getLink(attributes)` | Returns an `<a>` anchor element. Pass in an array of HTML attributes to override any.
`getLinkAttributes(attributes, asString)` | Returns a collection of HTML attributes to be used when creating an `<a>` HTML element. You can also have this returned as a string instead of an array.
`getCustomLinkText()` | Returns only the Link Text field value (`null` when blank). Does not apply element titles or other type-specific fallbacks—use `getText()` for the resolved label.

## Element Link
An Element Link is an extension of a regular Link object, and is inherited by all element-base link types like an Entry, Category, etc.

### Attributes

Attribute | Description
--- | ---
`linkSiteId` | The site ID for the chosen linked element.


## Embed Link
An Embed Link contains extra content fetched from the link target.

### Methods

Method | Description
--- | ---
`getHtml()` | Returns the HTML `code` for rendering a preview of the media.
`getData()` | Returns the raw data as fetched from the link target.
