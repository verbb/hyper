# Creating Links Programatically
You can create [Link](docs:developers/link) objects programatically for cases where you might want to add links to a Hyper field in your own code. To do this, you'll need to create the `Link` object, and assign it to the field on the element that stores your Hyper field.

For example, let's say we have a Hyper field called `ctaLink` attached to an entry.

```php
$value = new \verbb\hyper\links\Url();
$value->linkText = 'some text';
$value->linkValue = 'http://…';
$value->fields = [
    'myCustomField' => 'some value',
];

$entry->setFieldValue('ctaLink', [$value]);
```
 
Here, we set the type of link we want to use, the `linkText` and `linkValue` as applicable, and any custom fields (`fields`) that are set for the link type. We then use `$entry->setFieldValue()` or `$entry->setFieldValues()` to add that link to the field's value. Note that we always deal with an array of links!

Similarly, for an Entry link type:

```php
$value = new \verbb\hyper\links\Entry();
$value->linkValue = 1234;

$entry->setFieldValue('ctaLink', [$value]);
```

Where the `linkValue` represents the ID of the entry you are linking to.