# Creating Links Programatically
You can create [Link](docs:developers/link) objects programatically for cases where you might want to add links to a Hyper field in your own code. To do this, you'll need to create the `Link` object, and assign it to the field on the element that stores your Hyper field.

For example, let's say we have a Hyper field called `ctaLink` attached to an entry.

```php
// Create the Link for a URL link type
$link = new \verbb\hyper\links\Url();
$link->linkText = 'some text';
$link->linkValue = 'http://…';
$link->fields = [
    'myCustomField' => 'some value',
];

// Don't forget, Hyper always deal with a collection of links
$links = [$link];

// Set the `ctaLink` field handle to our collection of links
$entry->setFieldValues([
    'ctaLink' => $links,
]);
```
 
Here, we set the type of link we want to use, the `linkText` and `linkValue` as applicable, and any custom fields (`fields`) that are set for the link type. We then use `$entry->setFieldValue()` or `$entry->setFieldValues()` to add that link to the field's value. Note that we always deal with an array of links!

Similarly, for an Entry link type:

```php
$link = new \verbb\hyper\links\Entry();
$link->linkValue = 1234;

$links = [$link];

$entry->setFieldValues([
    'ctaLink' => $links,
]);
```

Where the `linkValue` represents the ID of the entry you are linking to.

You can also supply this same content in an array format, instead of an class object like the above `verbb\hyper\links\Url` example. This might be useful for Matrix fields.

```php
$entry = \craft\elements\Entry::find()->id(123)->one();

$entry->setFieldValues([
    'matrixFieldHandle' => [
        'new1' => [
            'type' => 'blockTypeHandle',
            'fields' => [
                'hyperFieldHandle' => [
                    'links' => [
                        'type' => 'verbb\\hyper\\links\\Entry',
                        'handle' => 'default-verbb-hyper-links-entry',
                        'linkValue' => 123,
                    ],
                ],
            ],
        ],
    ],
]);

Craft::$app->getElements()->saveElement($entry);
```
