<?php

use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\services\DraftConflicts;

it('retains successive edits to canonical Matrix children owned by a draft', function(int $depth, bool $provisional) {
    $fixture = F::matrixFieldWithHyper(F::hyperField(['multipleLinks' => true]));
    $sibling = new PlainText(['name' => 'Sibling', 'handle' => F::handle('sibling')]);
    expect(Craft::$app->fields->saveField($sibling))->toBeTrue();
    F::attachFieldToEntryType($fixture['blockEntryType'], $sibling);
    $levels = [[$fixture['matrix'], $fixture['blockEntryType']]];
    if ($depth === 2) {
        $type = new EntryType(['name' => 'Outer', 'handle' => F::handle('outer'), 'hasTitleField' => false]);
        expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
        F::attachFieldToEntryType($type, $fixture['matrix']);
        $matrix = new Matrix(['name' => 'Outer', 'handle' => F::handle('outerMatrix')]);
        $matrix->setEntryTypes([$type]);
        expect(Craft::$app->fields->saveField($matrix))->toBeTrue();
        array_unshift($levels, [$matrix, $type]);
    }
    $owner = F::plainEntry(F::entrySectionWithField($levels[0][0]));
    $admin = User::find()->admin()->one();
    $draft = Craft::$app->drafts->createDraft($owner, $admin->id, provisional: $provisional);
    $parent = $draft;
    foreach ($levels as [$matrix, $type]) {
        $child = new Entry(['fieldId' => $matrix->id, 'typeId' => $type->id, 'siteId' => $owner->siteId, 'title' => 'New child']);
        $child->setPrimaryOwner($parent);
        $child->setOwner($parent);
        expect(Craft::$app->elements->saveElement($child, false))->toBeTrue();
        $parent = $child;
    }
    $field = $child->getFieldLayout()->getFieldByHandle($fixture['hyperField']->handle);
    $token = DraftConflicts::inputToken($field, $child);
    $first = F::urlLinkPayload('/first');

    // The browser keeps its original token as autosaves write this draft's new canonical child.
    foreach ([[$first], [$first, F::urlLinkPayload('/second')]] as $links) {
        $current = Entry::find()->id($child->id)->status(null)->one();
        $current->setFieldValue($field->handle, $links);
        $current->setFieldValue($sibling->handle, 'Sibling ' . count($links));
        CpActionRequest::asUser($admin, function() use ($current, $field, $token) {
            Craft::$app->request->setBodyParams(['fields' => [$field->handle . '__hyperDraftBaseline' => $token]]);
            expect(Craft::$app->elements->saveElement($current))->toBeTrue();
        });
    }
    expect(Entry::find()->draftOf($child->id)->provisionalDrafts(true)->status(null)->count())->toBe(0);
    $draft = Entry::find()->id($draft->id)->drafts(true)->provisionalDrafts(null)->status(null)->one();
    Craft::$app->drafts->applyDraft($draft);
    $saved = Entry::find()->id($owner->id)->one();
    foreach ($levels as [$matrix]) {
        $saved = $saved->getFieldValue($matrix->handle)->one();
        expect($saved)->not->toBeNull();
    }
    expect(array_map(fn($link) => $link->linkValue, $saved->getFieldValue($field->handle)->getLinks()))->toBe(['/first', '/second'])
        ->and($saved->getFieldValue($sibling->handle))->toBe('Sibling 2');
})->with([[1, false], [1, true], [2, false], [2, true]]);

it('still protects published Matrix children viewed through a draft owner', function() {
    $fixture = F::matrixFieldWithHyper();
    $owner = F::entryWithMatrixHyperLink(
        F::entrySectionWithField($fixture['matrix']), $fixture['matrix'], $fixture['hyperField'],
        $fixture['blockEntryType'], [F::urlLinkPayload('/original')],
    );
    $child = $owner->getFieldValue($fixture['matrix']->handle)->one();
    $field = $child->getFieldLayout()->getFieldByHandle($fixture['hyperField']->handle);
    $token = DraftConflicts::inputToken($field, $child);
    $child->setFieldValue($field->handle, [F::urlLinkPayload('/published')]);
    expect(Craft::$app->elements->saveElement($child))->toBeTrue();
    $admin = User::find()->admin()->one();
    $draft = Craft::$app->drafts->createDraft($owner, $admin->id);
    $stale = Entry::find()->id($child->id)->status(null)->one();
    $stale->setOwner($draft);
    $stale->setFieldValue($field->handle, [F::urlLinkPayload('/stale')]);
    CpActionRequest::asUser($admin, function() use ($stale, $field, $token) {
        Craft::$app->request->setBodyParams(['fields' => [$field->handle . '__hyperDraftBaseline' => $token]]);
        expect(Craft::$app->elements->saveElement($stale))->toBeFalse();
        expect($stale->getErrors($field->handle)[0])->toContain('Your draft is saved');
    });
    expect(Entry::find()->id($child->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/published');
});
