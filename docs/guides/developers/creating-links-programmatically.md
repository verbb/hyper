# Creating Links Programmatically

Use PHP to populate Hyper links when importing content or creating entries in a module or content migration. This guide creates a News entry with a resource link, saves it, and reads it back to check the result.

Start in a development project with a News section whose handle is `news`, an entry type with handle `newsPost` assigned to that section, and a Hyper field with handle `resourceLinks` in that entry type’s layout. Enable URL and Entry on the Hyper field. See [Creating Hyper Fields Programmatically](/guides/developers/creating-hyper-fields-programmatically) if you need to create the field first.

The PHP examples belong inside a module’s console action or a content migration’s `safeUp()` method. Put imports at the top of the PHP file. Replace the sample section, entry type and field handles to match your project. Run the creation example once; each execution creates an entry.

## Create an Entry with a URL Link

```php
use craft\elements\Entry;
use verbb\hyper\links\Url;

$section = Craft::$app->getEntries()->getSectionByHandle('news');
$entryType = Craft::$app->getEntries()->getEntryTypeByHandle('newsPost');
if (!$section || !$entryType) {
    throw new \RuntimeException('Create the News section and News Post entry type first.');
}

$entry = new Entry([
    'sectionId' => $section->id,
    'typeId' => $entryType->id,
    'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
    'title' => 'Useful Resources',
    'slug' => 'useful-resources',
]);

$link = new Url();
$link->linkValue = 'https://verbb.io';
$link->linkText = 'Visit Verbb';
$link->newWindow = true;

$entry->setFieldValue('resourceLinks', [$link]);

if (!Craft::$app->getElements()->saveElement($entry)) {
    throw new \RuntimeException('Could not save entry: ' . json_encode($entry->getErrors()));
}
```

Hyper expects a collection, including for a single-link field, so the example wraps `$link` in an array. The entry’s other required fields must also be satisfied before Craft can save it. Check any reported validation errors rather than assuming the save succeeded.

Hyper associates the URL object with the destination field’s configured link type and layout. If the field has several URL types and you need a particular one, use its handle in an array payload as shown below.

## Read the Saved Value

Continue after the save with:

```php
$saved = Entry::find()
    ->id($entry->id)
    ->siteId($entry->siteId)
    ->status(null)
    ->one();
if (!$saved) {
    throw new \RuntimeException('The saved entry could not be reloaded.');
}

$links = $saved->getFieldValue('resourceLinks');
if ($links->first()?->getCustomLinkText() !== 'Visit Verbb' || $links->getUrl() !== 'https://verbb.io') {
    throw new \RuntimeException('The saved resource link did not match the expected values.');
}
```

Open Useful Resources in the control panel and confirm its destination, label and new-window setting. To display it, put this in that entry’s Twig template:

```twig
{{ entry.resourceLinks.getLink() }}
```

You should see Visit Verbb as a link to the saved address.

## Use an Array Payload

You can supply link data without constructing a Link object. This replacement example selects the type by its configured handle and updates the entry created above:

```php
$entry->setFieldValue('resourceLinks', [
    [
        'linkTypeHandle' => 'url',
        'linkValue' => 'https://verbb.io',
        'linkText' => 'Visit Verbb',
        'newWindow' => true,
    ],
]);

if (!Craft::$app->getElements()->saveElement($entry)) {
    throw new \RuntimeException(json_encode($entry->getErrors()));
}
```

Find the exact handle in the link type’s settings. Built-in URL uses `url`; a custom instance may have a different handle. The [Link reference](/reference/link#saved-content) describes the accepted input identifiers and saved representation.

## Link to Another Entry

To replace the URL link with an Entry link, first obtain the destination. This example assumes a published News entry with the slug `contact` in the same site:

```php
$targetEntry = Entry::find()
    ->section('news')
    ->slug('contact')
    ->siteId($entry->siteId)
    ->one();
if (!$targetEntry) {
    throw new \RuntimeException('Create and publish the contact entry first.');
}

$entry->setFieldValue('resourceLinks', [[
    'linkTypeHandle' => 'entry',
    'linkValue' => $targetEntry->id,
    'linkSiteId' => $targetEntry->siteId,
    'linkText' => 'Contact our team',
]]);
if (!Craft::$app->getElements()->saveElement($entry)) {
    throw new \RuntimeException(json_encode($entry->getErrors()));
}
```

Reload the entry and inspect its first link’s `getElement()` result. Its ID and site should match the selected destination. A destination without a usable URL cannot render an ordinary anchor.

## Add Custom Fields or Several Links

For a custom layout field, place its value inside the link’s `fields` array using its field handle. For example, `'fields' => ['summary' => 'Read about our team']` requires a Summary field in that link type’s layout. Use the normal value format accepted by the destination Craft field.

With **Enable Multiple Links** on, pass several link arrays in the outer array. Their order becomes the saved link order. Set the Hyper field to `[]` and save to clear its links.

For a Hyper field nested in Matrix or Neo, supply that same array at the Hyper field’s position inside the parent field’s content payload. The parent field determines the surrounding payload format; see its own saving API rather than treating Matrix and Neo as interchangeable. [Link](/reference/link) lists the native attributes you can set.
