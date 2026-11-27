<?php

use craft\controllers\ElementsController;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidElementException;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;
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

function hyperNestedEditor(): User
{
    $name = F::handle('hyperNestedEditor');
    $editor = new User(['username' => $name, 'email' => $name . '@example.test', 'pending' => false, 'admin' => true]);
    expect(Craft::$app->elements->saveElement($editor))->toBeTrue();

    return User::find()->id($editor->id)->status(null)->one();
}

function hyperNestedSlideoutEdit(Entry $owner, Matrix $matrix, User $user, array $fields): Entry
{
    $ownerDraft = Entry::find()->draftOf($owner->id)->provisionalDrafts(true)->draftCreator($user->id)->status(null)->one()
        ?? Craft::$app->drafts->createDraft($owner, $user->id, provisional: true);
    $child = $owner->getFieldValue($matrix->handle)->status(null)->one();
    $params = ['elementType' => Entry::class, 'elementId' => $child->id, 'siteId' => $child->siteId, 'fieldId' => $matrix->id, 'ownerId' => $ownerDraft->id];

    // The card slideout autosaves a provisional draft of the published child, then its Save
    // button moves that draft into the editor's owner draft as a derivative of the child.
    $viewed = Entry::find()->id($child->id)->ownerId($ownerDraft->id)->status(null)->one();
    $nested = Craft::$app->drafts->createDraft($viewed, $user->id, provisional: true);
    $nested->setFieldValues($fields);
    expect(Craft::$app->elements->saveElement($nested))->toBeTrue();
    $response = CpActionRequest::run('save-nested-element-for-derivative', $user, $params + ['draftId' => $nested->draftId, 'provisional' => 1, 'newOwnerId' => $ownerDraft->id, 'fields' => $fields], controllerClass: ElementsController::class);
    expect($response->statusCode)->toBe(200);

    // The owner editor then autosaves its draft with the Matrix field marked as changed.
    $ownerDraft = Entry::find()->id($ownerDraft->id)->drafts(true)->provisionalDrafts(true)->status(null)->one();
    $ownerDraft->setDirtyFields([$matrix->handle]);
    expect(Craft::$app->elements->saveElement($ownerDraft))->toBeTrue();

    return Entry::find()->id($ownerDraft->id)->drafts(true)->provisionalDrafts(true)->status(null)->one();
}

function hyperNestedConcurrentFixture(int $siteCount = 1): array
{
    if ($siteCount > 1) {
        F::ensureSites($siteCount);
    }

    $fixture = F::matrixFieldWithHyper(F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true]));
    $sibling = new PlainText(['name' => 'Sibling', 'handle' => F::handle('sibling')]);
    expect(Craft::$app->fields->saveField($sibling))->toBeTrue();
    F::attachFieldToEntryType($fixture['blockEntryType'], $sibling);
    $section = F::entrySection(allSites: $siteCount > 1);
    F::attachFieldToSection($section, $fixture['matrix']);
    $owner = F::entryWithMatrixHyperLink(
        Craft::$app->entries->getSectionById($section->id), $fixture['matrix'], $fixture['hyperField'],
        Craft::$app->entries->getEntryTypeById($fixture['blockEntryType']->id), [F::urlLinkPayload('/one'), F::urlLinkPayload('/two'), F::urlLinkPayload('/three')],
    );

    return [$owner, $fixture['matrix'], $fixture['hyperField'], $sibling];
}

function hyperNestedLinks(Entry $owner, Matrix $matrix, string $handle, ?int $siteId = null): array
{
    $child = Entry::find()->id($owner->id)->siteId($siteId ?? $owner->siteId)->one()->getFieldValue($matrix->handle)->one();

    return array_map(fn($link) => $link->linkValue, $child->getFieldValue($handle)->getLinks());
}

it('blocks a concurrent edit to the same Hyper field of a Matrix child published through owner drafts', function(int $siteCount) {
    [$owner, $matrix, $field] = hyperNestedConcurrentFixture($siteCount);
    $admin = User::find()->admin()->one();
    $editor = hyperNestedEditor();
    $adminDraft = hyperNestedSlideoutEdit($owner, $matrix, $admin, [$field->handle => [F::urlLinkPayload('/admin'), F::urlLinkPayload('/two'), F::urlLinkPayload('/three')]]);
    $editorDraft = hyperNestedSlideoutEdit($owner, $matrix, $editor, [$field->handle => [F::urlLinkPayload('/one'), F::urlLinkPayload('/two'), F::urlLinkPayload('/editor')]]);
    Craft::$app->drafts->applyDraft($editorDraft);
    expect(hyperNestedLinks($owner, $matrix, $field->handle))->toBe(['/one', '/two', '/editor']);

    expect(fn() => Craft::$app->drafts->applyDraft($adminDraft))->toThrow(InvalidElementException::class);

    foreach (Craft::$app->sites->getAllSiteIds() as $siteId) {
        expect(hyperNestedLinks($owner, $matrix, $field->handle, $siteId))->toBe(['/one', '/two', '/editor']);
    }

    $retained = Entry::find()->ownerId($adminDraft->id)->fieldId($matrix->id)->status(null)->one();
    expect(array_map(fn($link) => $link->linkValue, $retained->getFieldValue($field->handle)->getLinks()))->toBe(['/admin', '/two', '/three']);
})->with([1, 2]);

it('publishes concurrent edits to different fields of the same Matrix child through owner drafts', function() {
    [$owner, $matrix, $field, $sibling] = hyperNestedConcurrentFixture();
    $admin = User::find()->admin()->one();
    $editor = hyperNestedEditor();
    $adminDraft = hyperNestedSlideoutEdit($owner, $matrix, $admin, [$sibling->handle => 'Admin text']);
    $editorDraft = hyperNestedSlideoutEdit($owner, $matrix, $editor, [$field->handle => [F::urlLinkPayload('/one'), F::urlLinkPayload('/two'), F::urlLinkPayload('/editor')]]);
    Craft::$app->drafts->applyDraft($editorDraft);
    Craft::$app->drafts->applyDraft($adminDraft);
    $child = Entry::find()->id($owner->id)->one()->getFieldValue($matrix->handle)->one();
    expect(hyperNestedLinks($owner, $matrix, $field->handle))->toBe(['/one', '/two', '/editor'])
        ->and($child->getFieldValue($sibling->handle))->toBe('Admin text');
});

it('publishes a single editor\'s Hyper edit to a Matrix child through the owner draft', function() {
    [$owner, $matrix, $field] = hyperNestedConcurrentFixture();
    $draft = hyperNestedSlideoutEdit($owner, $matrix, User::find()->admin()->one(), [$field->handle => [F::urlLinkPayload('/only'), F::urlLinkPayload('/two')]]);
    Craft::$app->drafts->applyDraft($draft);
    expect(hyperNestedLinks($owner, $matrix, $field->handle))->toBe(['/only', '/two']);
});

it('publishes a Hyper edit to a Matrix child made after its owner draft merged newer published content', function() {
    [$owner, $matrix, $field, $sibling] = hyperNestedConcurrentFixture();
    $admin = User::find()->admin()->one();
    $editor = hyperNestedEditor();
    $adminDraft = hyperNestedSlideoutEdit($owner, $matrix, $admin, [$sibling->handle => 'Admin text']);
    $editorDraft = hyperNestedSlideoutEdit($owner, $matrix, $editor, [$field->handle => [F::urlLinkPayload('/one'), F::urlLinkPayload('/two'), F::urlLinkPayload('/editor')]]);
    $state = Craft::$app->state;
    // Craft records the published child's changed fields when the request ends.
    Craft::$app->state = \craft\web\Application::STATE_AFTER_REQUEST;

    try {
        Craft::$app->drafts->applyDraft($editorDraft);
    } finally {
        Craft::$app->state = $state;
    }

    Craft::$app->elements->mergeCanonicalChanges($adminDraft);
    $derivative = Entry::find()->ownerId($adminDraft->id)->fieldId($matrix->id)->status(null)->one();
    expect(array_map(fn($link) => $link->linkValue, $derivative->getFieldValue($field->handle)->getLinks()))->toBe(['/one', '/two', '/editor']);
    $derivative->setFieldValue($field->handle, [F::urlLinkPayload('/one'), F::urlLinkPayload('/two'), F::urlLinkPayload('/editor'), F::urlLinkPayload('/admin')]);
    expect(Craft::$app->elements->saveElement($derivative))->toBeTrue();
    Craft::$app->drafts->applyDraft(Entry::find()->id($adminDraft->id)->drafts(true)->provisionalDrafts(true)->status(null)->one());
    expect(hyperNestedLinks($owner, $matrix, $field->handle))->toBe(['/one', '/two', '/editor', '/admin']);
});
