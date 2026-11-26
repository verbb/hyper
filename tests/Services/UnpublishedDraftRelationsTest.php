<?php

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use benf\neo\helpers\Memoize;
use benf\neo\models\BlockType;
use craft\db\Query;
use craft\elements\ContentBlock as ContentBlockElement;
use craft\elements\Entry;
use craft\elements\db\EntryQuery;
use craft\elements\User;
use craft\events\DraftEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fields\ContentBlock as ContentBlockField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;

// Mirrors the control panel's "New entry" → autosave → "Create entry" flow, where Craft
// applies the unpublished draft with a single-site, non-propagating save.
it('indexes every site and nested owner when an unpublished draft is applied', function(string $container) {
    $sites = F::ensureSites(2);
    $siteIds = array_map(fn($site) => $site->id, $sites);
    $hyper = F::hyperField();
    $section = F::entrySection(null, null, true);
    F::attachFieldToSection($section, $hyper);

    if ($container === 'matrix') {
        $nested = F::matrixFieldWithHyper();
        $nestedField = $nested['matrix'];
        $nestedHyper = $nested['hyperField'];
        $nestedValue = fn($target) => [
            'entries' => ['new1' => [
                'type' => $nested['blockEntryType']->handle,
                'fields' => [$nestedHyper->handle => [F::entryLinkPayload($target, 'Nested')]],
            ]],
            'sortOrder' => ['new1'],
        ];
    } elseif ($container === 'contentBlock') {
        $nestedHyper = F::hyperField();
        $layout = new FieldLayout(['type' => ContentBlockElement::class]);
        $layout->setTabs([new FieldLayoutTab([
            'name' => 'Content',
            'layout' => $layout,
            'elements' => [new CustomField($nestedHyper)],
        ])]);
        $nestedField = new ContentBlockField(['name' => 'Content Block', 'handle' => F::handle('hyperTestContentBlock')]);
        $nestedField->setFieldLayout($layout);
        expect(Craft::$app->fields->saveField($nestedField))->toBeTrue();
        $nestedValue = fn($target) => [
            'fields' => [$nestedHyper->handle => [F::entryLinkPayload($target, 'Nested')]],
        ];
    } else {
        expect(Craft::$app->plugins->isPluginEnabled('neo'))->toBeTrue();
        $nestedHyper = F::hyperField();
        $layout = new FieldLayout(['type' => Block::class]);
        $layout->setTabs([new FieldLayoutTab([
            'name' => 'Content',
            'layout' => $layout,
            'elements' => [new CustomField($nestedHyper)],
        ])]);
        $type = new BlockType(['name' => 'Links', 'handle' => 'links', 'sortOrder' => 1]);
        $type->setFieldLayout($layout);
        $nestedField = new NeoField(['name' => 'Neo', 'handle' => F::handle('hyperTestNeo')]);
        $nestedField->setBlockTypes([$type]);
        expect(Craft::$app->fields->saveField($nestedField))->toBeTrue();
        // Neo memoizes the unsaved block type while the field is created.
        unset(Memoize::$blockTypesByFieldId[$nestedField->id]);
        $nestedField = Craft::$app->fields->getFieldById($nestedField->id);
        $nestedValue = fn($target) => ['new1' => [
            'type' => 'links',
            'enabled' => true,
            'level' => 1,
            'fields' => [$nestedHyper->handle => [F::entryLinkPayload($target, 'Nested')]],
        ]];
    }

    F::attachFieldToSection($section, $nestedField);
    $section = Craft::$app->entries->getSectionById($section->id);
    $target = F::plainEntry($section, 'Target');
    $admin = User::find()->admin()->one();

    // "New entry" creates an unpublished draft that has not been formally saved yet.
    $entry = new Entry([
        'sectionId' => $section->id,
        'typeId' => $section->getEntryTypes()[0]->id,
        'siteId' => $siteIds[0],
        'title' => 'Created in the control panel',
        'slug' => F::handle('cp-created'),
    ]);
    expect(Craft::$app->drafts->saveElementAsDraft($entry, $admin->id, null, null, false))->toBeTrue();

    // Autosave writes the links and nested block against the draft, which stays unindexed.
    $draft = Entry::find()->id($entry->id)->drafts(true)->status(null)->one();
    $draft->setFieldValue($hyper->handle, [F::entryLinkPayload($target, 'Owner')]);
    $draft->setFieldValue($nestedField->handle, $nestedValue($target));
    expect(Craft::$app->elements->saveElement($draft, false))->toBeTrue();

    $rows = fn(int $ownerId, int $fieldId) => array_map('intval', (new Query())->select('ownerSiteId')
        ->from('{{%hyper_links}}')->where(['ownerId' => $ownerId, 'fieldId' => $fieldId, 'targetId' => $target->id])
        ->orderBy('ownerSiteId')->column());
    expect($rows($entry->id, $hyper->id))->toBe([]);

    $draft = Entry::find()->id($entry->id)->drafts(true)->status(null)->one();
    $canonical = Craft::$app->drafts->applyDraft($draft);
    expect($canonical->getIsDraft())->toBeFalse();

    $nestedIds = (new Query())->select('elementId')->from('{{%elements_owners}}')
        ->where(['ownerId' => $canonical->id])->column();
    expect($nestedIds)->toHaveCount(1);

    expect([
        'owner' => $rows($canonical->id, $hyper->id),
        'nested' => $rows((int)$nestedIds[0], $nestedHyper->id),
    ])->toBe(['owner' => $siteIds, 'nested' => $siteIds]);
})->with(['matrix', 'neo', 'contentBlock']);

it('logs rather than throws when indexing fails after an unpublished draft is applied', function() {
    // Craft has already committed the apply by the time the handler runs.
    $canonical = new class () extends Entry {
        public static function find(): EntryQuery
        {
            throw new RuntimeException('Index unavailable');
        }
    };
    $canonical->id = 123;
    $event = new DraftEvent(['canonical' => $canonical, 'draft' => $canonical]);

    Hyper::$plugin->getLinkRelations()->handleAfterApplyDraft($event);

    expect(true)->toBeTrue();
});
