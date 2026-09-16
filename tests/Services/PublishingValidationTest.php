<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('enforces link validation on publication without overwriting saved content', function(array $values, bool $valid, string $errorKey) {
    $field = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true]);
    $field->minLinks = 1;
    $field->maxLinks = 2;
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $payload = fn(array $urls) => array_map(fn($url) => ['handle' => 'url', 'linkValue' => $url], $urls);
    $owner = F::plainEntry(F::entrySection($field), 'Published owner', [$field->handle => $payload(['https://example.test/original'])]);
    $owner->setAuthorIds([User::find()->admin()->one()->id]);
    $owner->setFieldValue($field->handle, $payload($values));
    $owner->setScenario(Element::SCENARIO_LIVE);
    expect(Craft::$app->elements->saveElement($owner))->toBe($valid, json_encode($owner->getErrors()));
    if (!$valid) {
        expect($owner->getErrors($field->handle . $errorKey))->not->toBeEmpty();
    }
    $storedUrls = fn($entry) => array_map(fn($link) => $link->linkValue, $entry->getFieldValue($field->handle)->getLinks());
    expect($storedUrls(Entry::find()->id($owner->id)->one()))->toBe($valid ? $values : ['https://example.test/original']);

    if (!$valid) {
        // Incomplete authoring remains saveable as a draft, without altering the canonical entry.
        $canonical = Entry::find()->id($owner->id)->one();
        $draft = Craft::$app->drafts->createDraft($canonical, User::find()->admin()->one()->id, 'Incomplete links');
        $draft->setFieldValue($field->handle, $payload($values));
        $draft->setScenario(Element::SCENARIO_DEFAULT);
        expect(Craft::$app->elements->saveElement($draft))->toBeTrue();
        expect($storedUrls(Entry::find()->id($draft->id)->drafts(true)->status(null)->one()))->toBe($values);
        expect($storedUrls(Entry::find()->id($owner->id)->one()))->toBe(['https://example.test/original']);
    }
})->with([
    'below minimum' => [[], false, ''],
    'minimum' => [['https://example.test/one'], true, ''],
    'maximum' => [['https://example.test/one', 'https://example.test/two'], true, ''],
    'above maximum' => [['https://example.test/one', 'https://example.test/two', 'https://example.test/three'], false, ''],
    'invalid destination' => [['javascript:alert(1)'], false, '[0].linkValue'],
]);
