<?php

use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidElementException;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

function hyperConcurrentFixture(): array
{
    $first = F::hyperField(['linkTypes' => [Url::class], 'multipleLinks' => true]);
    $second = F::hyperField(['linkTypes' => [Url::class]]);
    $section = F::entrySection($first);
    F::attachHyperFieldToSection($section, $second);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $layout = $type->getFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([new \craft\fieldlayoutelements\entries\EntryTitleField(), ...$tab->getElements()]);
    $type->setFieldLayout($layout);
    expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
    $owner = F::plainEntry($section, 'Concurrent owner', [
        $first->handle => [F::urlLinkPayload('/a'), F::urlLinkPayload('/b')],
        $second->handle => [F::urlLinkPayload('/separate')],
    ]);
    $user = User::find()->admin()->one();
    $a = Craft::$app->drafts->createDraft($owner, $user->id, 'Editor A');
    $b = Craft::$app->drafts->createDraft($owner, $user->id, 'Editor B');
    return [$owner, $first, $second, $a, $b];
}

function hyperConcurrentEdit(Entry $draft, string $handle, array $urls): Entry
{
    $links = $draft->getFieldValue($handle)->getLinks();
    foreach ($urls as $index => $url) {
        $links[$index]->linkValue = $url;
    }
    $draft->setFieldValue($handle, $links);
    expect(Craft::$app->elements->saveElement($draft))->toBeTrue();
    return Entry::find()->id($draft->id)->drafts(true)->status(null)->one();
}

it('blocks different link edits in the same field and retains the losing draft through reload and merge', function() {
    [$owner, $field, , $a, $b] = hyperConcurrentFixture();
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/admin-a']);
    $b = hyperConcurrentEdit($b, $field->handle, [1 => '/reviewer-b']);
    Craft::$app->drafts->applyDraft($a);
    Craft::$app->elements->mergeCanonicalChanges($b);
    $b = Entry::find()->id($b->id)->drafts(true)->status(null)->one();
    expect(fn() => Craft::$app->drafts->applyDraft($b))->toThrow(InvalidElementException::class, 'Your draft is saved');
    $published = Entry::find()->id($owner->id)->one();
    expect(array_map(fn($link) => $link->linkValue, $published->getFieldValue($field->handle)->getLinks()))->toBe(['/admin-a', '/b']);
    $retained = Entry::find()->id($b->id)->drafts(true)->status(null)->one();
    expect(array_map(fn($link) => $link->linkValue, $retained->getFieldValue($field->handle)->getLinks()))->toBe(['/a', '/reviewer-b']);
    expect($b->getErrors($field->handle))->not->toBeEmpty();
});

it('allows concurrent changes to separate Hyper fields', function() {
    [$owner, $first, $second, $a, $b] = hyperConcurrentFixture();
    $a = hyperConcurrentEdit($a, $first->handle, [0 => '/admin-a']);
    $b = hyperConcurrentEdit($b, $second->handle, [0 => '/reviewer-other']);
    Craft::$app->drafts->applyDraft($a);
    Craft::$app->drafts->applyDraft($b);
    $published = Entry::find()->id($owner->id)->one();
    expect($published->getFieldValue($first->handle)->getUrl())->toBe('/admin-a')
        ->and($published->getFieldValue($second->handle)->getUrl())->toBe('/reviewer-other');
});

it('allows the same final field value from both editors', function() {
    [$owner, $field, , $a, $b] = hyperConcurrentFixture();
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/same']);
    $b = hyperConcurrentEdit($b, $field->handle, [0 => '/same']);
    Craft::$app->drafts->applyDraft($a);
    Craft::$app->drafts->applyDraft($b);
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/same');
});

it('blocks deleting links that another editor has changed', function() {
    [$owner, $field, , $a, $b] = hyperConcurrentFixture();
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/admin-a']);
    $b->setFieldValue($field->handle, []);
    expect(Craft::$app->elements->saveElement($b))->toBeTrue();
    Craft::$app->drafts->applyDraft($a);
    $b = Entry::find()->id($b->id)->drafts(true)->status(null)->one();
    expect(fn() => Craft::$app->drafts->applyDraft($b))->toThrow(InvalidElementException::class);
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/admin-a');
});

it('uses the rendered baseline when the first edit happens after another editor publishes', function() {
    [$owner, $field, , $a] = hyperConcurrentFixture();
    $layoutField = $owner->getFieldLayout()->getFieldByHandle($field->handle);
    $token = \verbb\hyper\services\DraftConflicts::inputToken($layoutField, $owner);
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/admin-a']);
    Craft::$app->drafts->applyDraft($a);
    $current = Entry::find()->id($owner->id)->one();
    $late = Craft::$app->drafts->createDraft($current, User::find()->admin()->one()->id, 'Late first edit');
    $originalRequest = Craft::$app->request;
    $request = new \craft\web\Request(['isConsoleRequest' => false, 'isCpRequest' => false]);
    $request->setBodyParams(['fields' => [$field->handle . '__hyperDraftBaseline' => $token]]);
    Craft::$app->set('request', $request);
    try {
        // The stale browser still posts the original first link with its edited second link.
        $late = hyperConcurrentEdit($late, $field->handle, [0 => '/a', 1 => '/late-b']);
    } finally {
        Craft::$app->set('request', $originalRequest);
    }
    expect(fn() => Craft::$app->drafts->applyDraft($late))->toThrow(InvalidElementException::class);
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/admin-a');
});

it('does not overwrite published changes when the draft edit was reverted', function() {
    [$owner, $field, , $a, $b] = hyperConcurrentFixture();
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/admin-a']);
    $b = hyperConcurrentEdit($b, $field->handle, [0 => '/temporary']);
    $b = hyperConcurrentEdit($b, $field->handle, [0 => '/a']);
    Craft::$app->drafts->applyDraft($a);
    Craft::$app->drafts->applyDraft($b);
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/admin-a');
});

it('allows recovery by copying changes to a fresh draft after discarding the conflict', function() {
    [$owner, $field, , $a, $b] = hyperConcurrentFixture();
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/admin-a']);
    $b = hyperConcurrentEdit($b, $field->handle, [1 => '/reviewer-b']);
    Craft::$app->drafts->applyDraft($a);
    expect(fn() => Craft::$app->drafts->applyDraft($b))->toThrow(InvalidElementException::class);
    expect(Craft::$app->elements->deleteElement($b, true))->toBeTrue();
    $fresh = Craft::$app->drafts->createDraft(Entry::find()->id($owner->id)->one(), User::find()->admin()->one()->id, 'Recovered changes');
    $fresh = hyperConcurrentEdit($fresh, $field->handle, [1 => '/reviewer-b']);
    Craft::$app->drafts->applyDraft($fresh);
    $published = Entry::find()->id($owner->id)->one();
    expect(array_map(fn($link) => $link->linkValue, $published->getFieldValue($field->handle)->getLinks()))->toBe(['/admin-a', '/reviewer-b']);
});

it('protects nested Hyper edits as part of their parent field', function() {
    $inner = F::hyperField(['linkTypes' => [Url::class]]);
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new \craft\fieldlayoutelements\CustomField($inner)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $owner = F::plainEntry(F::entrySection($field), 'Nested concurrent owner', [$field->handle => [[
        ...F::urlLinkPayload('/parent'), 'fields' => [$inner->handle => [F::urlLinkPayload('/child')]],
    ]]]);
    $user = User::find()->admin()->one();
    $a = Craft::$app->drafts->createDraft($owner, $user->id, 'Parent editor');
    $b = Craft::$app->drafts->createDraft($owner, $user->id, 'Child editor');
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/parent-edited']);
    $parent = $b->getFieldValue($field->handle)->getLinks()[0];
    $parent->setFieldValue($inner->handle, [F::urlLinkPayload('/child-edited')]);
    $b->setFieldValue($field->handle, [$parent]);
    expect(Craft::$app->elements->saveElement($b))->toBeTrue();
    Craft::$app->drafts->applyDraft($a);
    $b = Entry::find()->id($b->id)->drafts(true)->status(null)->one();
    expect(fn() => Craft::$app->drafts->applyDraft($b))->toThrow(InvalidElementException::class);
    $published = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getLinks()[0];
    expect($published->linkValue)->toBe('/parent-edited')
        ->and($published->getFieldValue($inner->handle)->getUrl())->toBe('/child');
});

it('retains independent localized content and blocks conflicts on another site', function() {
    [$primary, $secondary] = F::ensureSites(2);
    $field = F::hyperField(['linkTypes' => [Url::class], 'translationMethod' => \craft\base\Field::TRANSLATION_METHOD_SITE]);
    $owner = F::plainEntry(F::translatableEntrySection($field, 2), 'Localized owner', [$field->handle => [F::urlLinkPayload('/original')]], $primary);
    $user = User::find()->admin()->one();
    $a = Craft::$app->drafts->createDraft($owner, $user->id, 'Primary');
    $b = Craft::$app->drafts->createDraft($owner, $user->id, 'Secondary');
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/primary']);
    $bSecondary = Entry::find()->id($b->id)->drafts(true)->siteId($secondary->id)->status(null)->one();
    $bSecondary->setFieldValue($field->handle, [F::urlLinkPayload('/secondary')]);
    expect(Craft::$app->elements->saveElement($bSecondary))->toBeTrue();
    Craft::$app->drafts->applyDraft($a);
    $b = Entry::find()->id($b->id)->drafts(true)->siteId($primary->id)->status(null)->one();
    Craft::$app->drafts->applyDraft($b);
    expect(Entry::find()->id($owner->id)->siteId($primary->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/primary')
        ->and(Entry::find()->id($owner->id)->siteId($secondary->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/secondary');

    $owner = Entry::find()->id($owner->id)->siteId($primary->id)->one();
    $c = Craft::$app->drafts->createDraft($owner, $user->id, 'Other site A');
    $d = Craft::$app->drafts->createDraft($owner, $user->id, 'Other site B');
    foreach ([[$c, '/third'], [$d, '/fourth']] as [$draft, $value]) {
        $other = Entry::find()->id($draft->id)->drafts(true)->siteId($secondary->id)->status(null)->one();
        $other->setFieldValue($field->handle, [F::urlLinkPayload($value)]);
        expect(Craft::$app->elements->saveElement($other))->toBeTrue();
    }
    Craft::$app->drafts->applyDraft(Entry::find()->id($c->id)->drafts(true)->siteId($primary->id)->status(null)->one());
    expect(fn() => Craft::$app->drafts->applyDraft(Entry::find()->id($d->id)->drafts(true)->siteId($primary->id)->status(null)->one()))->toThrow(InvalidElementException::class);
    expect(Entry::find()->id($owner->id)->siteId($secondary->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/third');
});

it('blocks unverified legacy draft edits without deleting their content', function() {
    [$owner, $field, , , $draft] = hyperConcurrentFixture();
    Craft::$app->db->createCommand()->delete('{{%hyper_draft_baselines}}', ['draftId' => $draft->id])->execute();
    $draft = hyperConcurrentEdit($draft, $field->handle, [0 => '/legacy-edit']);
    expect(fn() => Craft::$app->drafts->applyDraft($draft))->toThrow(InvalidElementException::class, 'could not be verified');
    expect(Entry::find()->id($draft->id)->drafts(true)->status(null)->one()->getFieldValue($field->handle)->getUrl())->toBe('/legacy-edit')
        ->and(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/a');
});

it('checks changes even when a caller cached modified fields before saving', function() {
    [$owner, $field, , $a, $b] = hyperConcurrentFixture();
    $b->getModifiedFields();
    $b->setFieldValue($field->handle, [F::urlLinkPayload('/cached-b')]);
    expect(Craft::$app->elements->saveElement($b))->toBeTrue();
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/admin-a']);
    Craft::$app->drafts->applyDraft($a);
    expect(fn() => Craft::$app->drafts->applyDraft($b))->toThrow(InvalidElementException::class);
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/admin-a');
});

it('uses published content as the baseline for a draft created with initial field values', function() {
    [$owner, $field] = hyperConcurrentFixture();
    $draft = Craft::$app->drafts->createDraft($owner, User::find()->admin()->one()->id, 'Initial edits', newAttributes: [
        'fieldValues' => [$field->handle => [F::urlLinkPayload('/initial-edit')]],
    ]);
    Craft::$app->drafts->applyDraft($draft);
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/initial-edit');
});

it('blocks an immediate canonical save and preserves all edits in a provisional draft', function() {
    [$owner, $field, $other, $a] = hyperConcurrentFixture();
    $layoutField = $owner->getFieldLayout()->getFieldByHandle($field->handle);
    $token = \verbb\hyper\services\DraftConflicts::inputToken($layoutField, $owner);
    $a = hyperConcurrentEdit($a, $field->handle, [0 => '/admin-a']);
    Craft::$app->drafts->applyDraft($a);
    $stale = Entry::find()->id($owner->id)->one();
    $links = $owner->getFieldValue($field->handle)->getLinks();
    $links[1]->linkValue = '/immediate-b';
    $stale->setFieldValue($field->handle, $links);
    $stale->setFieldValue($other->handle, [F::urlLinkPayload('/other-edit')]);
    $stale->title = 'Retained title edit';
    $stale->postDate = new DateTime('2025-01-02T12:00:00+00:00');
    $admin = User::find()->admin()->one();
    \Tests\Support\CpActionRequest::asUser($admin, function() use ($field, $token, $stale, $layoutField) {
        Craft::$app->request->setBodyParams(['postDate' => '2025-01-02T12:00:00+00:00', 'fields' => [$field->handle . '__hyperDraftBaseline' => $token]]);
        expect(Craft::$app->elements->saveElement($stale))->toBeFalse();
        expect($stale->getErrors($field->handle)[0])->toContain('Your draft is saved');
        expect(\verbb\hyper\services\DraftConflicts::inputToken($layoutField, $stale))->toBe($token);
    });
    $retained = Entry::find()->draftOf($owner->id)->provisionalDrafts(true)->draftCreator($admin->id)->status(null)->one();
    expect($retained)->not->toBeNull()
        ->and($retained->title)->toBe('Retained title edit')
        ->and($retained->postDate->format('Y-m-d H:i'))->toBe('2025-01-02 12:00')
        ->and($retained->getFieldValue($other->handle)->getUrl())->toBe('/other-edit')
        ->and($retained->getFieldValue($field->handle)->getLinks()[1]->linkValue)->toBe('/immediate-b')
        ->and(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('/admin-a');
    expect(fn() => Craft::$app->drafts->applyDraft($retained))->toThrow(InvalidElementException::class);
});
