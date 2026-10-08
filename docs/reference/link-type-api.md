# Link Type API

Registered link classes define destination behaviour and provide settings and input controls. Start with [Creating Link Types](/developers/creating-link-types) for a complete registration example. These methods are extension points for subclasses of `verbb\hyper\base\Link`.

## Settings and Input Configuration

::: reference
### `displayName(): string`

Static method returning the name shown to editors.
:::

::: reference
### `getSettingsConfig(): array`

Return the settings Hyper should save for this link type. Start with the parent method’s values when adding your own settings.
:::

::: reference
### `getInputConfig(): array`

Return the configuration and current values needed by the browser input.
:::

::: reference
### `getSettingsHtmlVariables(): array`

Template variables including `linkType`.
:::

::: reference
### `getInputHtmlVariables(LinkField $layoutField, HyperField $field): array`

Input context including `link`, `layoutField` and `field`.
:::

::: reference
### `getSettingsHtml(): ?string`

Type-specific settings controls.
:::

::: reference
### `getInputHtml(LinkField $layoutField, HyperField $field): ?string`

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

Resolve the type-specific destination before prefix, suffix and URL-policy checks.
:::

::: reference
### `getLinkText(): ?string`

Resolve the type’s label, including appropriate defaults.
:::

::: reference
### `isInstanceEmpty(LinkInstance $instance): bool`

Use this storage and authoring check to recognise entered destinations, labels, attributes or custom fields without requiring a resolvable URL. It is separate from a Link’s public `isEmpty()`, which checks its resolved destination. Preserve that separation when extending a link type so temporarily unavailable destinations do not discard authored content.
:::

::: reference
### `getRequiredPlugins(): array`

Static list of plugins needed for this type to be available.
:::

::: reference
### `supportsBulkCreation(): bool`

Return `true` to allow editors to add several links of this type at once. This is a static method.
:::

::: reference
### `bulkCreationMode(): ?string`

Return the bulk input mode, such as `text` or `elements`, when bulk creation is enabled. This is a static method.
:::


Element types extend `verbb\hyper\base\ElementLink` and implement the static `elementType(): string` method. `supportsUriSelectorCriteria(): bool` controls whether URI-based selector settings apply. Keep `getElement()` nullable and respect the destination’s site and status.

The registry event is `verbb\hyper\services\Links::EVENT_REGISTER_LINK_TYPES` with a `craft\events\RegisterComponentTypesEvent`. Append your class to `$event->types` when registering it.
