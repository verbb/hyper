<?php

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

it('preserves an element selection through partial updates and clears it explicitly', function() {
    $field = F::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Selected target');
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [F::entryLinkPayload($target)]]);
    $links = $owner->getFieldValue($field->handle);
    $link = $links->first();
    expect($link->getElement()?->id)->toBe($target->id);
    $link->setAttributes(['linkText' => 'Changed label'], false);
    $owner->setFieldValue($field->handle, $links);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $saved = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->first();
    expect($saved->getElement()?->id)->toBe($target->id);
    expect($saved->getText())->toBe('Changed label');
    $saved->setAttributes(['linkValue' => []], false);
    expect($saved->getElement())->toBeNull();
    expect($saved->getSerializedValues()['linkValue'] ?? null)->toBeNull();
});

it('uses the owner site for selected element cards without changing the content shape', function() {
    [$primary, $secondary] = F::ensureSites(2);
    $field = F::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = F::translatableEntrySection($field, 2);
    $target = F::plainEntry($section, 'Selected target', [], $primary);
    $owner = F::plainEntry($section, 'Owner', [], $secondary);
    $links = $field->normalizeValue([['linkTypeHandle' => 'entry', 'linkValue' => [$target->id]]], $owner);
    $link = $links->first();
    $before = $link->getSerializedValues();
    expect($link->getElement()?->siteId)->toBe($secondary->id);
    expect($link->getElements()[0]->siteId ?? null)->toBe($secondary->id);
    expect($link->getSerializedValues())->toBe($before);
});
