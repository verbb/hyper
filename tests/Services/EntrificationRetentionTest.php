<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Category;
use verbb\hyper\links\Entry;
use verbb\hyper\migrations\MigrateEntrifyCategories;

it('retains category custom data through entrification and an ordinary owner save', function(bool $legacyArray) {
    $field = F::hyperField(['linkTypes' => [Category::class, Entry::class]]);
    $section = F::entrySection($field);
    // Craft entrification has already retained this ID as an Entry.
    $target = F::plainEntry($section, 'Entrified destination');
    $raw = [[
        'linkTypeHandle' => 'category',
        'linkValue' => $legacyArray ? [$target->id] : $target->id,
        'linkText' => 'Keep my label',
        'fields' => ['historicalNote' => '00123 & café'],
    ]];
    $migration = new MigrateEntrifyCategories();
    $converted = $migration->convertModel($field, $raw);
    expect($converted[0]['fields'] ?? null)->toBe($raw[0]['fields']);
    expect($converted[0]['linkValue'])->toBe($target->id);
    $owner = F::plainEntry($section, 'Owner', [$field->handle => $converted]);
    $owner = craft\elements\Entry::find()->id($owner->id)->one();
    $link = $owner->getFieldValue($field->handle)->getLinks()[0];
    expect($link)->toBeInstanceOf(Entry::class);
    expect($link->getUrl())->toBe($target->getUrl());
    expect($link->getSerializedValues()['fields']['historicalNote'] ?? null)->toBe('00123 & café');
    expect($migration->convertModel($field, $field->serializeValue($owner->getFieldValue($field->handle), $owner)))->toBeNull();
})->with([false, true]);

it('leaves category content unchanged when no Entry link type is enabled', function() {
    $field = F::hyperField(['linkTypes' => [Category::class]]);
    $raw = [['linkTypeHandle' => 'category', 'linkValue' => 123, 'fields' => ['note' => 'Keep']]];
    expect((new MigrateEntrifyCategories())->convertModel($field, $raw))->toBeNull();
    expect($raw[0]['fields'])->toBe(['note' => 'Keep']);
});
