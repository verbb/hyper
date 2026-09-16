# Creating Link Types

Create a link type when your site needs destination behaviour that the built-in types do not provide. If you only need a different label, restricted sources or extra custom fields, configure another instance of an existing type in [Link Type Configs](/feature-tour/link-type-configs) instead.

This example registers a Documentation URL type in a site module. It reuses Hyper’s URL controls and validation, with a prefilled documentation address. You need PHP development access and a working Craft module autoloaded as `modules\sitemodule`. Use Craft’s [module setup instructions](https://craftcms.com/docs/5.x/extend/module-guide.html) to establish that module first.

## Create the Class

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

Replace the default URL with your documentation address. Extending `Url` retains its validation and settings, including Placeholder, Default Link Value and Fixed Link Value. The two rendering methods explicitly use Hyper’s URL templates; otherwise the base class derives a template path from the custom class name.

`getInputHtmlVariables()` requires the layout field and Hyper field passed to `getInputHtml()`. These supply the context needed to render the input in the editor.

## Register the Type

In your existing module class, add these imports after its namespace:

```php
use craft\events\RegisterComponentTypesEvent;
use modules\sitemodule\DocumentationUrl;
use verbb\hyper\services\Links;
use yii\base\Event;
```

Inside that module’s `init()` method, after `parent::init()`, add:

```php
Event::on(Links::class, Links::EVENT_REGISTER_LINK_TYPES, function(RegisterComponentTypesEvent $event) {
    $event->types[] = DocumentationUrl::class;
});
```

Ensure your module is bootstrapped in both web and console requests, so the type is available when editors save links and when commands read them. This registration belongs in the existing module; it is not a complete standalone module file.

## Enable and Test It

Open your Hyper field’s Custom settings or its shared link type config. Enable Documentation URL, save the settings and add a link of that type to an entry. Its destination should begin with your documentation address. Change the address or label, save the entry, then reopen it to confirm the values were retained.

Render the link with `getLink()` in the entry template and follow it. If the type does not appear, check the module’s bootstrap configuration and the class’s autoload mapping. If rendering reports a missing template, confirm that both HTML methods use the explicit URL template paths shown above.

## Settings and Saved Values

Settings configure a type across its links. Content describes one editor-created link. Keep those roles separate when extending the class. The inherited URL class already includes `defaultLinkValue` in its settings, so the example does not need its own serialisation method.

For an additional setting, override `getSettingsConfig()` and merge it into `parent::getSettingsConfig()`. If the browser input needs additional configuration, override `getInputConfig()` and start from `parent::getInputConfig()`. The [Link Type API](/reference/link-type-api) shows these extension points.

Store a custom destination’s value inside `linkValue`, and use custom layout fields for additional editor content. Adding an arbitrary top-level property in `getSerializedValues()` alone does not make that property part of Hyper’s LinkInstance contract. See [Saved Content](/reference/link#saved-content) before designing a custom payload.

## Element Link Types

Extend `ElementLink` when editors should select a Craft element. Its `elementType()` method returns the element class. Reuse `hyper/links/_element/settings` and `hyper/links/_element/input` with the corresponding settings and input variables, as in the URL example.

An element type without a URI, such as a form, needs `supportsUriSelectorCriteria()` to return `false` so URI-based selector rules do not hide its choices. It also needs a destination strategy if you expect an anchor: being selectable does not give the element a URL. Hyper includes a Formie Form type for selecting forms, so an extension should add your site’s specific behaviour rather than register a duplicate.

## Custom Templates

The settings template receives `linkType`; the input template receives `link`, `layoutField` and `field`. A link input’s destination control must submit under `linkValue`. The surrounding Hyper editor supplies the link layout, tabs and other fields.

When you need your own templates, register a control-panel template root in your module and use that root in `renderTemplate()`. A site’s frontend template directory is not automatically a control-panel template root. Escape values in inputs and use Craft’s form macros where appropriate. See Craft’s [template root documentation](https://craftcms.com/docs/5.x/extend/template-roots.html).
