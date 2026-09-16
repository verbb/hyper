<?php

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('persists programmatic custom field updates on a hydrated link', function() {
    $caption = new PlainText(['name' => 'Caption', 'handle' => F::handle('hyperCaption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($caption), new CustomField($related)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($field), 'Custom field owner', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test/custom', 'fields' => [$caption->handle => 'Original caption', $related->handle => [999999999]],
    ]]]);
    $owner = Entry::find()->id($owner->id)->one();
    $links = $owner->getFieldValue($field->handle);
    $link = $links->first();
    expect($link->getFieldValue($caption->handle))->toBe('Original caption');
    $link->setFieldValue($caption->handle, 'Updated caption');
    $owner->setFieldValue($field->handle, $links);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $saved = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first();
    expect($saved->getFieldValue($caption->handle))->toBe('Updated caption');

    // Explicit clearing through the same API must also replace the original value.
    $saved->setFieldValue($caption->handle, '');
    $owner->setFieldValue($field->handle, [$saved]);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $cleared = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first();
    expect($cleared->getFieldValue($caption->handle))->toBeNull();
    // An unrelated edit must retain raw IDs whose targets cannot currently resolve.
    $relatedUid = $cleared->getFieldLayout()->getFieldByHandle($related->handle)->layoutElement->uid;
    expect($cleared->getSerializedValues()['fields'][$relatedUid])->toBe([999999999]);
});

it('serializes normalized relation values assigned to a link custom field', function(string $input) {
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($related)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Selected custom relation');
    $owner = F::plainEntry($section, 'Custom relation owner', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test/relation',
    ]]]);
    $links = $owner->getFieldValue($field->handle);
    $query = Entry::find()->id($target->id);
    $links->first()->setFieldValue($related->handle, $input === 'query' ? $query : $query->collect());
    $owner->setFieldValue($field->handle, $links);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $saved = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first();
    expect(array_map('intval', $saved->getFieldValue($related->handle)->ids()))->toBe([$target->id]);
    expect(array_values($saved->getSerializedValues()['fields']))->toBe([[$target->id]]);
})->with(['query', 'collection']);
