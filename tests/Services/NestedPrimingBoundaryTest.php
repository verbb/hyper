<?php

use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

it('does not query unused Matrix content when reading owner titles', function() {
    $type = new craft\models\EntryType(['name' => 'Unrelated body', 'handle' => F::handle('plainBody'), 'hasTitleField' => true]);
    expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
    $matrix = new craft\fields\Matrix(['name' => 'Unrelated Matrix', 'handle' => F::handle('plainMatrix')]);
    $matrix->setEntryTypes([$type]);
    expect(Craft::$app->fields->saveField($matrix))->toBeTrue();
    $section = F::entrySectionWithField($matrix);
    $ids = [];
    for ($i = 0; $i < 25; $i++) {
        $owner = F::plainEntry($section, 'Listing ' . $i, [$matrix->handle => [
            'entries' => ['new1' => ['type' => $type->handle, 'title' => 'Body ' . $i]], 'sortOrder' => ['new1'],
        ]]);
        $ids[] = $owner->id;
    }
    $service = Hyper::$plugin->linkRelations;
    $measure = function(bool $enabled) use ($service, $ids) {
        $service->resetRequestState();
        $service->enableRequestPriming = $enabled;
        return QueryProfiler::profile(fn() => array_map(fn($entry) => $entry->title, Entry::find()->id($ids)->all()));
    };
    try {
        $baseline = $measure(false);
        $enabled = $measure(true);
        expect($enabled['resultSize'])->toBe(25);
        expect($enabled['queries'])->toBeLessThanOrEqual($baseline['queries'] + 2);
    } finally {
        $service->enableRequestPriming = true;
    }
});

it('batches linked target fields when nested owners are populated', function(string $mode) {
    $related = F::entriesField();
    $targetSection = F::entrySectionWithField($related);
    $destination = F::plainEntry($targetSection, 'Related destination');
    ['matrix' => $matrix, 'blockEntryType' => $type, 'hyperField' => $field] = F::matrixFieldWithHyper();
    $ownerSection = F::entrySectionWithField($matrix);
    $ids = [];
    $targets = [];
    for ($i = 0; $i < 5; $i++) {
        $target = F::plainEntry($targetSection, 'Target ' . $i, [$related->handle => [$destination->id]]);
        $targets[] = $target->id;
        $owner = F::entryWithMatrixHyperLink($ownerSection, $matrix, $field, $type, [F::entryLinkPayload($target)]);
        $ids[] = $owner->id;
    }
    Hyper::$plugin->linkRelations->resetRequestState();
    $prefix = in_array($mode, ['native lazy', 'lazy eager', 'late eager'], true) ? '' : $matrix->handle . '.';
    $with = [$prefix . $field->handle . '.linkedElements.' . $related->handle];
    if ($mode === 'query eager') {
        $with[] = $matrix->handle;
    }
    $owners = Entry::find()->id($ids)->orderBy('elements.id ASC')->with($with)->all();
    if ($mode === 'late eager') {
        Craft::$app->elements->eagerLoadElements(Entry::class, $owners, [$matrix->handle]);
    }
    $resolved = [];
    foreach ($owners as $owner) {
        $blocks = $owner->getFieldValue($matrix->handle);
        $block = $mode === 'lazy eager' ? $blocks->eagerly()->one() : $blocks->one();
        $target = $block->getFieldValue($field->handle)->first()->getElement();
        $resolved[] = $target->id;
        expect($target->hasEagerLoadedElements($related->handle))->toBeTrue();
        expect($target->getFieldValue($related->handle)->one()?->id)->toBe($destination->id);
    }
    expect($resolved)->toBe($targets);
})->with(['nested path', 'query eager', 'native lazy', 'late eager', 'lazy eager']);
