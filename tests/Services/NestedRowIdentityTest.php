<?php

use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('preserves distinct Matrix identities and sibling content through Hyper JSON and editor submission', function() {
    ['matrix' => $matrix, 'blockEntryType' => $type, 'hyperField' => $inner] = F::matrixFieldWithHyper();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($matrix)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $rows = [];
    foreach (['first', 'second'] as $index => $label) {
        $rows['new' . ($index + 1)] = ['type' => $type->handle, 'fields' => [
            $inner->handle => [F::urlLinkPayload('https://example.test/' . $label)],
        ]];
    }
    $owner = F::plainEntry(F::entrySection($field), 'Nested identities', [
        $field->handle => [[...F::urlLinkPayload('https://example.test/parent'), 'fields' => [
            $matrix->handle => ['entries' => $rows, 'sortOrder' => ['new1', 'new2']],
        ]]],
    ]);
    $link = $owner->getFieldValue($field->handle)->first();
    $entries = $link->getFieldValue($matrix->handle)->all();
    $uids = array_map(fn($entry) => $entry->uid, $entries);
    expect($uids)->toHaveCount(2);
    expect(array_filter($uids, StringHelper::isUUID(...)))->toHaveCount(2);
    expect(array_unique($uids))->toHaveCount(2);

    $serialized = $field->serializeValue($owner->getFieldValue($field->handle), $owner);
    $link = $field->normalizeValue($serialized, $owner)->first();
    $entries = $link->getFieldValue($matrix->handle)->all();
    expect(array_map(fn($entry) => $entry->uid, $entries))->toBe($uids);

    // Native Matrix submits each rendered row by UUID. Edit only the second.
    $posted = [];
    foreach ($entries as $index => $entry) {
        $posted['uid:' . $entry->uid] = ['type' => $type->handle, 'fields' => [
            $inner->handle => [F::urlLinkPayload('https://example.test/' . ($index ? 'changed' : 'first'))],
        ]];
    }
    $link->setFieldValue($matrix->handle, ['entries' => $posted, 'sortOrder' => $uids]);
    $copy = $field->normalizeValue([$link->getSerializedValues()], $owner)->first();
    $entries = $copy->getFieldValue($matrix->handle)->all();
    expect(array_map(fn($entry) => $entry->uid, $entries))->toBe($uids);
    expect(array_map(fn($entry) => $entry->getFieldValue($inner->handle)->getUrl(), $entries))
        ->toBe(['https://example.test/first', 'https://example.test/changed']);

    // Already-normalized, unsaved rows need the same identity guarantee.
    $pending = $matrix->normalizeValue(['entries' => $rows, 'sortOrder' => ['new1', 'new2']], $link);
    $link->setFieldValue($matrix->handle, $pending);
    $copy = $field->normalizeValue([$link->getSerializedValues()], $owner)->first();
    $entries = $copy->getFieldValue($matrix->handle)->all();
    $pendingUids = array_map(fn($entry) => $entry->uid, $entries);
    expect(array_filter($pendingUids, StringHelper::isUUID(...)))->toHaveCount(2);
    expect(array_unique($pendingUids))->toHaveCount(2);
    expect(array_map(fn($entry) => $entry->getFieldValue($inner->handle)->getUrl(), $entries))
        ->toBe(['https://example.test/first', 'https://example.test/second']);
});
