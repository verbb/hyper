# Link Type Settings

Each link type on a Hyper field has a **definition** stored in field settings (project config): handle, label, enabled state, type-specific options (sources, placeholders), and a **field layout** for link attributes.

Editors see the layout when creating a link in the CP. In templates, attribute values live on the hydrated [Link](/reference/link) object.

## Link type definition

Property | Description
--- | ---
`label` | CP label for this link type in the type switcher.
`handle` | Stable identifier. Used in content JSON (`linkTypeHandle`), GraphQL type names, and multisite propagation. Built-in types use a short, read-only **type key** (`url`, `entry`, …). Custom instances generate an editable handle from their label. The settings builder includes a copy button for programmatic saves.
`enabled` | Whether editors can select this type.
`isCustom` | Whether this row is a custom instance (e.g. a second Entry type scoped to one section).

Type-specific settings (element **sources**, selection label, URI filters, **selectable element conditions** for Entry/Asset/User, etc.) are stored on the definition and exposed on the registry link type class. See [Link Types](/feature-tour/link-types) for built-in types.

### Selectable element conditions

Entry, Asset, and User link types support Craft’s **Selectable {Type} Condition** builder — the same HTMX condition UI as native Entries/Assets/Users fields.

- Configured per link type under field settings, or via [link type configs](/feature-tour/link-type-configs).
- Applied to the CP element select modal and multi-link bulk-add picker.
- Stored as `selectionCondition` on the link type definition in project config.

Sources still limit *where* you pick from; the condition limits *which* elements within those sources are selectable.

## Native layout fields

These field layout elements ship with Hyper. Add them per link type under **Settings → Fields → [Hyper field] → Link Types → [type] → Link Fields**.

| Layout field | Link attribute | Template access | Notes |
| --- | --- | --- | --- |
| **Link** | `linkValue` | `linkValue`, type-specific helpers | Type-specific input (URL field, element selector, etc.). Value serializes into `linkValue`. |
| **Link Text** | `linkText` | `linkText`, `text`, `customLinkText` | `text` applies layout defaults and element title fallback; `customLinkText` is author input only. |
| **Link Title** | `linkTitle` | `linkTitle` | HTML `title` attribute. |
| **Aria Label** | `ariaLabel` | `ariaLabel` | HTML `aria-label` attribute. **Not** in the default layout — add via the field layout designer. |
| **Classes** | `classes` | `classes` | HTML `class` attribute. |
| **Url Suffix** | `urlSuffix` | `urlSuffix` | Appended to resolved URL (`?query`, `#fragment`, path segments). |
| **Custom Attributes** | `customAttributes` | `customAttributes` | Arbitrary HTML attributes (name/value pairs). |
| **New Window** | `newWindow` | `newWindow`, `target` | Lightswitch in the layout. **Not** in the default layout (same as ARIA Label). When added, the header icon is hidden for that link type. Field setting **Enable New Window in Header** must still be on. |
| **Embed Preview** | — | — | CP-only preview for Embed link types. |

Custom Craft fields and UI elements can be added to the layout. Values serialize into the link’s custom field content keyed by layout element UID.

## Layout tabs

Fields on each **field-layout tab** appear in the CP as Vizy/Neo/Matrix-style header tabs when there is more than one tab. Authors switch panes inline; there is no settings slide-out for layout tabs.

### Where to put `linkValue`

Keep the primary **Link** (`linkValue`) field on the **first tab**. That is the main authoring surface. Moving `linkValue` to a later tab makes empty or incomplete links easy to miss.

Use additional tabs for secondary attributes (URL Suffix, classes, custom fields), not for the core target value.

::: tip
Shared link type suites live in [Link Type Configs](/feature-tour/link-type-configs). Fields pick a named config or **Custom** for field-owned link types.
:::
