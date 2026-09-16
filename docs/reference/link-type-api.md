# Link Type API

Registered link classes define destination behaviour and provide settings and input controls. Start with [Creating Link Types](/developers/creating-link-types) for a complete registration example. These methods are extension points for subclasses of `verbb\hyper\base\Link`.

## Settings and Input Configuration

::: reference
### `displayName(): string`

**Purpose:** Name shown for the type.

Name shown for the type.
:::

::: reference
### `getSettingsConfig(): array`

**Purpose:** Settings to retain on the link type definition.

Settings to retain on the link type definition.
:::

::: reference
### `getInputConfig(): array`

**Purpose:** Configuration and current values supplied to the input.

Configuration and current values supplied to the input.
:::

::: reference
### `getSettingsHtmlVariables(): array`

**Purpose:** Template variables including `linkType`.

Template variables including `linkType`.
:::

::: reference
### `getInputHtmlVariables(LinkField $layoutField, HyperField $field): array`

**Purpose:** Input context including `link`, `layoutField` and `field`.

Input context including `link`, `layoutField` and `field`.
:::

::: reference
### `getSettingsHtml(): ?string`

**Purpose:** Type-specific settings controls.

Type-specific settings controls.
:::

::: reference
### `getInputHtml(LinkField $layoutField, HyperField $field): ?string`

**Purpose:** The destination input inside the Link layout field.

The destination input inside the Link layout field.
:::


The following is a partial class example. Add these members to your custom link class when its settings template includes a `hint` input and its browser input needs that hint:

```php
public ?string $hint = null;

public function getSettingsConfig(): array
{
    $values = parent::getSettingsConfig();
    $values['hint'] = $this->hint;

    return $values;
}

public function getInputConfig(): array
{
    $values = parent::getInputConfig();
    $values['hint'] = $this->hint;

    return $values;
}
```

`getSettingsConfig()` makes the setting available for persistence with the definition. `getInputConfig()` does not replace that persistence or save arbitrary editor content. Store editor values in `linkValue` or custom layout fields; see [Saved Content](/reference/link#saved-content).

## Destination Behaviour

::: reference
### `getLinkUrl(): ?string`

**Purpose:** Resolve the type-specific destination before prefix, suffix and URL-policy checks.

Resolve the type-specific destination before prefix, suffix and URL-policy checks.
:::

::: reference
### `getLinkText(): ?string`

**Purpose:** Resolve the type’s label, including appropriate defaults.

Resolve the type’s label, including appropriate defaults.
:::

::: reference
### `isInstanceEmpty(LinkInstance $instance): bool`

**Purpose:** Static check for meaningful saved content.

Static check for meaningful saved content.
:::

::: reference
### `getRequiredPlugins(): array`

**Purpose:** Static list of plugins needed for this type to be available.

Static list of plugins needed for this type to be available.
:::

::: reference
### `supportsBulkCreation(): bool`

**Purpose:** Static opt-in to bulk creation.

Static opt-in to bulk creation.
:::

::: reference
### `bulkCreationMode(): ?string`

**Purpose:** Static bulk input mode for an opted-in type.

Static bulk input mode for an opted-in type.
:::


Element types extend `verbb\hyper\base\ElementLink` and implement the static `elementType(): string` method. `supportsUriSelectorCriteria(): bool` controls whether URI-based selector settings apply. Keep `getElement()` nullable and respect the destination’s site and status.

The registry event is `verbb\hyper\services\Links::EVENT_REGISTER_LINK_TYPES` with a `craft\events\RegisterComponentTypesEvent`. Append your class to `$event->types` when registering it.
