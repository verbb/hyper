# Custom Link Types

Create a link type when your site needs destination behaviour that the built-in types do not provide. If you only need a different label, restricted sources or extra custom fields, configure another instance of an existing type in [Link Type Configs](/feature-tour/link-type-configs) instead.

This example registers a Documentation URL type in a site module. It reuses Hyper’s URL controls and validation, with a prefilled documentation address. You need PHP development access and a working Craft module autoloaded as `modules\sitemodule`. Use Craft’s [module setup instructions](https://craftcms.com/docs/5.x/extend/module-guide.html) to establish that module first.

## Create the Link Type Class

With `modules\sitemodule` mapped to your project’s `modules/sitemodule/` directory, create `modules/sitemodule/DocumentationUrl.php`:

```php
<?php
namespace modules\sitemodule;

use Craft;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Url;

class DocumentationUrl extends Url
{
    public ?string $defaultLinkValue = 'https://example.test/docs';

    public static function displayName(): string
    {
        return 'Documentation URL';
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'hyper/links/url/settings',
            $this->getSettingsHtmlVariables(),
        );
    }

    public function getInputHtml(LinkField $layoutField, HyperField $field): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'hyper/links/url/input',
            $this->getInputHtmlVariables($layoutField, $field),
        );
    }
}
```

Replace the default URL with your documentation address. Extending `Url` retains its validation and settings, including Placeholder, Default Link Value and Fixed Link Value. The two rendering methods explicitly use Hyper’s URL templates. Without them, the base class derives a template path from the custom class name.

`getInputHtmlVariables()` requires the layout field and Hyper field passed to `getInputHtml()`. These supply the context needed to render the input in the editor.

## Register the Link Type

Hyper finds link types through the `registerLinkTypes` event. Register your class from your module or plugin’s `init()` method.

```php
use craft\events\RegisterComponentTypesEvent;
use modules\sitemodule\DocumentationUrl;
use verbb\hyper\services\Links;
use yii\base\Event;

Event::on(Links::class, Links::EVENT_REGISTER_LINK_TYPES, function(RegisterComponentTypesEvent $event) {
    $event->types[] = DocumentationUrl::class;
});
```

Add the `use` lines after your module’s namespace and the `Event::on()` call after `parent::init()`. Ensure your module is bootstrapped in both web and console requests, so the type is available when editors save links and when commands read them.

## Enable and Test It

Open your Hyper field’s Custom settings or its shared link type config. Enable Documentation URL, save the settings and add a link of that type to an entry. Its destination should begin with your documentation address. Change the address or label, save the entry, then reopen it to confirm the values were retained.

Render the link with `getLink()` in the entry template and follow it. If the type does not appear, check the module’s bootstrap configuration and the class’s autoload mapping. If rendering reports a missing template, confirm that both HTML methods use the explicit URL template paths shown above.

## Settings and Saved Values

Settings configure a type across its links. Content describes one editor-created link. Keep those roles separate when extending the class. The inherited URL class already includes `defaultLinkValue` in its settings, so the example does not need its own serialisation method.

For an additional setting, override `getSettingsConfig()` and merge it into `parent::getSettingsConfig()`. If the browser input needs additional configuration, override `getInputConfig()` and start from `parent::getInputConfig()`. The following partial example adds these members to your link type class when its settings template includes a `hint` input and its browser input needs that hint:

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

`getSettingsConfig()` makes the setting available for saving with the link type definition. `getInputConfig()` doesn’t save anything, and editor content belongs in `linkValue` or custom layout fields. [Methods](#methods) describes each method you can override.

Store a custom destination’s value inside `linkValue`, and use custom layout fields for additional editor content. Adding another top-level property in `getSerializedValues()` does not make Hyper save and reload it. See [Saved Content](/reference/link#saved-content) before choosing how to store your values.

## Element Link Types

Extend `ElementLink` when editors should select a Craft element. Its `elementType()` method returns the element class. Keep `getElement()` nullable, and respect the destination’s site and status when you override it. Reuse `hyper/links/_element/settings` and `hyper/links/_element/input` with the corresponding settings and input variables, as in the URL example.

An element type without a URI, such as a form, needs `supportsUriSelectorCriteria()` to return `false` so URI-based selector rules do not hide its choices. It also needs a destination strategy if you expect an anchor: being selectable does not give the element a URL. Hyper includes a Formie Form type for selecting forms, so an extension should add your site’s specific behaviour rather than register a duplicate.

## Custom Templates

The settings template receives `linkType`, and the input template receives `link`, `layoutField` and `field`. A link input’s destination control must submit under `linkValue`. The surrounding Hyper editor supplies the link layout, tabs and other fields.

When you need your own templates, register a control-panel template root in your module and use that root in `renderTemplate()`. A site’s frontend template directory is not automatically a control-panel template root. Escape values in inputs and use Craft’s form macros where appropriate. See Craft’s [template root documentation](https://craftcms.com/docs/5.x/extend/template-roots.html).

## Methods

A link type class extends `verbb\hyper\base\Link`, or `verbb\hyper\base\ElementLink` for element links, and overrides these methods as needed.

::: reference
### `displayName()`

**Returns:** `string`

Static. Returns the name shown to editors when they choose a link type.
:::

::: reference
### `getSettingsConfig()`

**Returns:** `array`

Returns the settings Hyper saves for this link type. Start with `parent::getSettingsConfig()` when adding your own settings.
:::

::: reference
### `getInputConfig()`

**Returns:** `array`

Returns the configuration and current values the browser input needs. This doesn’t save editor content, which belongs in `linkValue` or custom layout fields.
:::

::: reference
### `getSettingsHtmlVariables()`

**Returns:** `array`

Returns the variables passed to the settings template, including `linkType`.
:::

::: reference
### `getSettingsHtml()`

**Returns:** `string|null`

Returns the type-specific settings controls shown in the field or link type config settings.
:::

::: reference
### `getInputHtmlVariables(LinkField $layoutField, HyperField $field)`

**Returns:** `array`

Returns the variables passed to the input template, including `link`, `layoutField` and `field`.
:::

::: reference
### `getInputHtml(LinkField $layoutField, HyperField $field)`

**Returns:** `string|null`

Returns the destination input shown inside the Link layout field in the editor.
:::

::: reference
### `getLinkUrl()`

**Returns:** `string|null`

Returns the type-specific destination before Hyper applies the URL prefix, suffix and URL checks.
:::

::: reference
### `getLinkText()`

**Returns:** `string|null`

Returns the link’s label, including any type-specific default such as an element’s title.
:::

::: reference
### `isInstanceEmpty(LinkInstance $instance)`

**Returns:** `bool`

Static. Returns whether a stored link has no authored content, such as a destination, label, attributes or custom fields. This is separate from a link’s `isEmpty()`, which checks its resolved destination, so a temporarily unavailable destination doesn’t discard authored content.
:::

::: reference
### `getRequiredPlugins()`

**Returns:** `array`

Static. Returns the plugin handles this type needs. Each item can also be an array with `handle` and `version` keys. Hyper hides the type unless every plugin is installed, enabled and at that version or greater.
:::

::: reference
### `supportsBulkCreation()`

**Returns:** `bool`

Static. Returns `true` to let editors add several links of this type at once.
:::

::: reference
### `bulkCreationMode()`

**Returns:** `string|null`

Static. Returns the bulk input mode, such as `text` or `elements`, when bulk creation is enabled.
:::

::: reference
### `elementType()`

**Returns:** `string`

Static, and required for classes extending `verbb\hyper\base\ElementLink`. Returns the element class editors can select.
:::

::: reference
### `supportsUriSelectorCriteria()`

**Returns:** `bool`

Static, for classes extending `ElementLink`. Return `false` for an element type without URIs, such as a form, so URI-based selector rules don’t hide its choices.
:::
