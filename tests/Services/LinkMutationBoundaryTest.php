<?php

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;

it('retains custom fields during a partial native-attribute update', function() {
    $caption = new PlainText(['name' => 'Caption', 'handle' => F::handle('hyperCaption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($caption)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($field), 'Partial update', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test', 'fields' => [$caption->handle => 'Retained caption'],
    ]]]);
    $links = $owner->getFieldValue($field->handle);
    $links->first()->setAttributes(['linkText' => 'Changed label'], false);
    $owner->setFieldValue($field->handle, $links);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $saved = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first();
    expect($saved->getCustomLinkText())->toBe('Changed label');
    expect($saved->getFieldValue($caption->handle))->toBe('Retained caption');
});

it('keeps custom-field state consistent when clearing or cloning a link', function(string $operation) {
    $caption = new PlainText(['name' => 'Caption', 'handle' => F::handle('hyperCaption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($caption)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $link = Hyper::$plugin->links->createLinkFromSerialized($field, [
        'handle' => 'url', 'linkValue' => 'https://example.test', 'fields' => [$caption->handle => 'Old caption'],
    ]);
    expect($link->getFieldValue($caption->handle))->toBe('Old caption');
    if ($operation === 'clear') {
        $link->setAttributes(['fields' => []], false);
        expect($link->getFieldValue($caption->handle))->toBeNull();
        expect($link->getSerializedValues()['fields'] ?? [])->toBe([]);
    } elseif ($operation === 'safe attributes') {
        $before = $link->getSerializedValues();
        $link->setAttributes(['fields' => []]);
        expect($link->getFieldValue($caption->handle))->toBe('Old caption');
        expect($link->getSerializedValues())->toBe($before);
    } else {
        $copy = clone $link;
        expect($copy->getFieldValue($caption->handle))->toBe('Old caption');
        $copy->setFieldValue($caption->handle, 'Copy caption');
        expect($link->getFieldValue($caption->handle))->toBe('Old caption');
        expect($copy->getFieldValue($caption->handle))->toBe('Copy caption');
    }
})->with(['clear', 'clone', 'safe attributes']);

it('applies destination settings to links inserted through collection mutation APIs', function(string $method) {
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig(new Url([
        'defaultLinkValue' => 'https://example.test/fixed', 'fixedLinkValue' => true,
    ]))]);
    $owner = F::plainEntry(F::entrySection($field), 'Collection edits');
    $links = new LinkCollection($field, [], $owner);
    $incoming = new Url(['handle' => 'url', 'linkValue' => 'https://example.test/changed', 'linkText' => 'Inserted']);
    if ($method === 'append') {
        $links[] = $incoming;
    } elseif ($method === 'setLinks') {
        $links->setLinks([$incoming]);
    } else {
        $links = $links->withLinks([$incoming]);
    }
    expect($links->getUrl())->toBe('https://example.test/fixed');
    expect($links->first()->ownerSiteId)->toBe($owner->siteId);
    $owner->setFieldValue($field->handle, $links);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first()->linkValue)->toBe('https://example.test/fixed');
    expect($incoming->linkValue)->toBe('https://example.test/changed');
})->with(['append', 'setLinks', 'withLinks']);

it('previews the first remaining link after an array-access removal', function() {
    $field = F::hyperField(['multipleLinks' => true, 'linkTypes' => [Url::class]]);
    $owner = F::plainEntry(F::entrySection($field), 'Preview');
    $links = new LinkCollection($field, [F::urlLinkPayload('https://example.test/first', 'First'), F::urlLinkPayload('https://example.test/second', 'Second')], $owner);
    unset($links[0]);
    expect($links->first()->getText())->toBe('Second');
    expect($field->getPreviewHtml($links, $owner))->toBe('Second');
});

it('binds a copied normalized collection to its receiving field and owner', function() {
    $sourceField = F::hyperField(['linkTypes' => [Url::class]]);
    $source = F::plainEntry(F::entrySection($sourceField), 'Source', [$sourceField->handle => [F::urlLinkPayload('https://example.test/source')]]);
    $destinationField = F::hyperFieldWithLinkTypes([F::linkTypeConfig(new Url([
        'defaultLinkValue' => 'https://example.test/destination', 'fixedLinkValue' => true,
    ]))]);
    $destination = F::plainEntry(F::entrySection($destinationField), 'Destination');
    $original = $source->getFieldValue($sourceField->handle);
    $destination->setFieldValue($destinationField->handle, $original);
    $copied = $destination->getFieldValue($destinationField->handle);
    expect($copied->getUrl())->toBe('https://example.test/destination');
    expect($copied)->not->toBe($original);
    expect($copied->first()->field->handle)->toBe($destinationField->handle);
    expect(Craft::$app->elements->saveElement($destination))->toBeTrue();
    expect(Entry::find()->id($destination->id)->one()->getFieldValue($destinationField->handle)->first()->linkValue)->toBe('https://example.test/destination');
    expect($original->getUrl())->toBe('https://example.test/source');
});
