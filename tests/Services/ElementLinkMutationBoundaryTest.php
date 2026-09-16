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

it('resolves custom relation fields in the owning site after loading and copying links', function(bool $copy) {
    [$primary, $secondary] = F::ensureSites(2);
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($related)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $section = F::translatableEntrySection($field, 2);
    $target = F::plainEntry($section, 'Related target', [], $primary);
    $payload = ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test', 'fields' => [$related->handle => [$target->id]]];
    $source = F::plainEntry($section, 'Source', [$field->handle => [$payload]], $primary);
    $owner = F::plainEntry($section, 'Owner', [], $secondary);
    if ($copy) {
        $value = $source->getFieldValue($field->handle);
        expect($value->first()->getFieldValue($related->handle)->one()?->siteId)->toBe($primary->id);
        $owner->setFieldValue($field->handle, $value);
    } else {
        $owner->setFieldValue($field->handle, [$payload]);
    }
    $assertSite = function($element) use ($field, $related, $secondary) {
        expect($element->getFieldValue($field->handle)->first()->getFieldValue($related->handle)->one()?->siteId)->toBe($secondary->id);
    };
    $assertSite($owner);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    $assertSite(Entry::find()->id($owner->id)->siteId($secondary->id)->one());
    if ($copy) {
        expect($value->first()->getFieldValue($related->handle)->one()?->siteId)->toBe($primary->id);
    }
})->with([false, true]);
