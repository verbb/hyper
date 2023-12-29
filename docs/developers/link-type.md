# Link Type
You can register your own Link Type to create your own specialised links, or even extend an existing Link Type.

```php
namespace modules\sitemodule;

use craft\events\RegisterComponentTypesEvent;
use modules\MyLinkType;
use verbb\hyper\services\Links;
use yii\base\Event;

Event::on(Links::class, Links::EVENT_REGISTER_LINK_TYPES, function(RegisterComponentTypesEvent $event) {
    $event->types[] = MyLinkType::class;
});
```

Because a link type is used to determine both the settings available for a link, and the value to be accessed in your templates, your class should define one or both of these things. It will largely depend on what sort of custom link type you wish to create.

## Example
Create the following class to house your Link Type logic.

```php
namespace modules\sitemodule;

use verbb\hyper\base\Link;

class MyLinkType extends Link 
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return 'My Link Type';
    }


    // Public Methods
    // =========================================================================

    public function getLinkUrl(): ?string
    {
        return 'http://my-site.test/some-url';
    }

    public function getLinkText(): ?string
    {
        return 'Some Text';
    }

    public function getSettingsHtml(): ?string
    {
        $variables = $this->getSettingsHtmlVariables();

        return Craft::$app->getView()->renderTemplate('path/to/settings', $variables);
    }

    public function getInputHtml(LinkField $layoutField, HyperField $field): ?string
    {
        $variables = $this->getInputHtmlVariables();

        return Craft::$app->getView()->renderTemplate('path/to/input', $variables);
    }
}
```

This is the minimum amount of implementation required for a typical link type. You can include any more methods and properties as required for your use-case. Be sure to read further on caveat's to this.

## Element Link Type
An Element Link Type extends from a Link Type, and should be used if you want to allow a Craft element to be able to be picked from. The implementation of this is very simple:

```php
<?php
namespace modules;

use craft\commerce\elements\Variant as VariantElement;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fields\HyperField;

class Variant extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function elementType(): string
    {
        return VariantElement::class;
    }

    public function getSettingsHtml(): ?string
    {
        $variables = $this->getSettingsHtmlVariables();

        return Craft::$app->getView()->renderTemplate('hyper/links/_element/settings', $variables);
    }
    
    public function getInputHtml(LinkField $layoutField, HyperField $field): ?string
    {
        $variables = $this->getInputHtmlVariables($layoutField, $field);

        return Craft::$app->getView()->renderTemplate('hyper/links/_element/input', $variables);
    }
}

```

In the above example, we've added support for Craft Commerce Variant elements to be able to be used as links. Fortunately, extending from the `ElementLink` class takes care of everything for you.

We're also falling back on Hypers templates for `getSettingsHtml()` and `getInputHtml()` but you could of course write your own.

One thing to note is that for element links, Hyper will only show elements for that element type that have a `uri`. If your element type does not support this, you'll need to disallow this with the `checkElementUri()` function.

For example, if we had a custom link type for Formie forms, which don't have an intrinsic `uri`:

```php
<?php
namespace modules;

use Craft;
use verbb\formie\elements\Form;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fields\HyperField;

class Formie extends ElementLink
{
    public static function elementType(): string
    {
        return Form::class;
    }

    public static function checkElementUri(): bool
    {
        return false;
    }

    public function getSettingsHtml(): ?string
    {
        $variables = $this->getSettingsHtmlVariables();

        return Craft::$app->getView()->renderTemplate("hyper/links/_element/settings", $variables);
    }
    
    public function getInputHtml(LinkField $layoutField, HyperField $field): ?string
    {
        $variables = $this->getInputHtmlVariables($layoutField, $field);

        return Craft::$app->getView()->renderTemplate("hyper/links/_element/input", $variables);
    }
}
```

Without this, no Formie Form element would be selectable when creating the link in Hyper.

## Settings, Variables and Values
As mentioned, because a single class takes care of 3 different uses (the field settings, the field input in the control panel when editing an element, the field when rendering on the front-end), the class does need to consider how to save properties.

To illustrate, let's look at an example custom link type.

```php
<?php
namespace modules;

use verbb\hyper\base\Link;

class ExampleLinkType extends Link 
{
    // Properties
    // =========================================================================

    public ?string $placeholder = null;
    public ?string $extraSetting = null;
    

    // Public Methods
    // =========================================================================

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['placeholder'] = $this->placeholder;

        return $values;
    }

    public function getInputConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['placeholder'] = $this->placeholder;
        $values['extraSetting'] = $this->extraSetting;

        return $values;
    }

    public function getSerializedValues(): array
    {
        $values = parent::getSerializedValues();
        $values['extraSetting'] = $this->extraSetting;

        return $values;
    }
}
```

Here, we have two properties `$placeholder` and `$extraSetting`. We want users to be able to provide a setting for the placeholder in the field settings for this link type. The value of this will be shown in the Hyper field when editing an element.

In order to save the placeholder to the field settings, we need to tell `getSettingsConfig()` about the `placeholder` variable. Now, when saving the Hyper field settings, this value will be stored alongside other settings.

Likewise, `extraSetting` is a property that we want users to be able to access in the Hyper field when editing an element, and when using on the front-end. We use `getInputConfig()` to add any extra variables for the Hyper field to use (we also include `placeholder`). Then, in order for the user's input to be saved in the content of the Hyper field for the element it's used on, we need to use `getSerializedValues()` to ensure that it's serialized in the value for the field.

You aren't required to use all of these, and it'll largely depend on what you're trying to do. If you want to just add settings for the link type that are used in configuring the input, or internally to your class, adding `getSettingsConfig()` would suffice.

Similarly, if you need extra properties for when rendering the link type in the field, and you don't require the user to save any content to that property, only `getInputConfig()` be used.

The point is that Hyper won't automatically assume your properties should be persisted or saved, so you'll need to instruct Hyper what to save, and where.

## Templates
You can provide your custom link type an input and a settings template. The settings template is shown when editing the Hyper field in Craft settings (when you select the link type), while the input template is shown when the user is editing an element and selects your custom link type in the Hyper field.

Settings templates will automatically have a **Label** and **Link Fields** setting available - the rest is up to you. You could provide additional fields for extra configuration.

Input templates don't provide free-reign over the template of the Hyper field. Instead, it's used as the **Link** field value. Because you can add custom fields and native fields to the field layout of a link type, that defines the layout of a link type. However, you have full control over what content your **Link** field shows.

For example, an Asset link type shows an element select field, while an Email field is a simple `<input>` element. The value of your input template will serialize into the `linkValue` property of the Link object. Again, this can be whatever you require, be it a simple string, array or object.
