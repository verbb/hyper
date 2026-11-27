<?php

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use benf\neo\helpers\Memoize;
use benf\neo\models\BlockType;
use craft\db\Query;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\console\controllers\ContentController;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

function contentModifyController(HyperField $field, bool $dryRun, bool $includeNested, ?string $elementIds = null): ContentController
{
    $controller = new class('content', verbb\hyper\Hyper::$plugin) extends ContentController {
        public string $output = '';
        public function stdout($string) { $this->output .= $string; }
    };
    $controller->field = $field->handle;
    $controller->dryRun = $dryRun;
    $controller->includeNested = $includeNested;
    $controller->elementIds = $elementIds;

    return $controller;
}

/**
 * A top-level owner and a Matrix entry nested in it, both storing the field in Hyper 2's serialised shape,
 * plus an entry saved in the current format. The current entry's revision and the copy of the nested entry
 * in the owner's revision hold two more copies of the field, both in the current format.
 */
function contentModifyFixture(): array
{
    $field = F::hyperField(['multipleLinks' => true]);
    $matrix = F::matrixFieldWithHyper($field);
    $section = F::entrySection($field);
    F::attachFieldToSection($section, $matrix['matrix']);
    $target = F::plainEntry($section, 'Modify target');

    // Save the owner with its Matrix content in one go, so it always gets exactly one revision. Saving it
    // empty first and then again (as `entryWithMatrixHyperLink()` does) only adds a second revision, with a
    // copy of the nested entry, when the two saves land in different seconds.
    $owner = F::plainEntry($section, 'Matrix Hyper owner', [
        $matrix['matrix']->handle => [
            'entries' => [
                'new1' => [
                    'type' => $matrix['blockEntryType']->handle,
                    'fields' => [$matrix['hyperField']->handle => [F::urlLinkPayload('https://example.test/nested')]],
                ],
            ],
            'sortOrder' => ['new1'],
        ],
    ]);
    $current = F::entryWithLinks($section, [F::urlLinkPayload('https://example.test/current', 'Current'), F::entryLinkPayload($target)]);
    $nestedId = (int)Entry::find()->ownerId($owner->id)->fieldId($matrix['matrix']->id)->status(null)->ids()[0];
    $legacy = [
        ['type' => Url::class, 'handle' => 'default-verbb-hyper-links-url', 'newWindow' => false, 'linkValue' => 'https://example.test/legacy', 'linkText' => 'Legacy URL'],
        ['type' => EntryLink::class, 'handle' => 'default-verbb-hyper-links-entry', 'newWindow' => false, 'linkValue' => [$target->id], 'linkText' => 'Legacy entry', 'linkSiteId' => $owner->siteId],
    ];

    foreach ([$owner->id, $nestedId] as $elementId) {
        $element = Entry::find()->id($elementId)->status(null)->one();
        $placement = $element->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
        $where = ['elementId' => $elementId, 'siteId' => $element->siteId];
        $content = Json::decode((new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar() ?: '{}');
        $content[$placement] = $legacy;
        Craft::$app->getDb()->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression($content)], $where)->execute();
    }

    Craft::$app->getDb()->createCommand()->delete('{{%hyper_links}}', ['fieldId' => $field->id])->execute();
    Craft::$app->getElements()->invalidateCachesForElementType(Entry::class);

    return ['field' => $field, 'owner' => $owner, 'nestedId' => $nestedId, 'current' => $current];
}

function contentModifyStored(int $elementId, HyperField $field): array
{
    $element = Entry::find()->id($elementId)->status(null)->one();
    $placement = $element->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $content = (new Query())->select('content')->from('{{%elements_sites}}')->where(['elementId' => $elementId, 'siteId' => $element->siteId])->scalar();

    return Json::decode($content)[$placement] ?? [];
}

function contentModifyRelationOwners(HyperField $field): array
{
    return array_map('intval', (new Query())->select('ownerId')->distinct()->from('{{%hyper_links}}')->where(['fieldId' => $field->id])->column());
}

it('modify reports and re-saves stored links that are not in the current format', function() {
    $f = contentModifyFixture();
    $field = $f['field'];
    $currentBefore = contentModifyStored($f['current']->id, $field);

    $dryRun = contentModifyController($field, true, true);
    expect($dryRun->actionModify())->toBe(0);
    expect($dryRun->output)->toContain("Matched: 5\nWould modify / modified: 2");
    expect(contentModifyStored($f['owner']->id, $field)[0])->toHaveKey('type');
    expect(contentModifyRelationOwners($field))->toBe([]);

    $write = contentModifyController($field, false, true);
    expect($write->actionModify())->toBe(0);
    expect($write->output)->toContain("Matched: 5\nWould modify / modified: 2");

    foreach ([$f['owner']->id, $f['nestedId']] as $elementId) {
        $stored = contentModifyStored($elementId, $field);
        expect(array_column($stored, 'linkTypeHandle'))->toBe(['url', 'entry']);
        expect(array_column($stored, 'linkValue'))->toContain('https://example.test/legacy');

        foreach ($stored as $link) {
            expect($link)->not->toHaveKey('type')->not->toHaveKey('handle');
            expect($link['uid'] ?? null)->toBeString();
        }
    }

    // A row already in the current format is left as it was.
    expect(contentModifyStored($f['current']->id, $field))->toBe($currentBefore);
    expect(contentModifyRelationOwners($field))->toEqualCanonicalizing([$f['owner']->id, $f['nestedId']]);

    $again = contentModifyController($field, true, true);
    $again->actionModify();
    expect($again->output)->toContain("Matched: 5\nWould modify / modified: 0");
});

it('modify and sync-relations skip Matrix nested entries when nested content is excluded', function() {
    $f = contentModifyFixture();
    $field = $f['field'];

    $withNested = contentModifyController($field, true, true);
    $withNested->actionModify();
    expect($withNested->output)->toContain("Matched: 5\nWould modify / modified: 2");

    $withoutNested = contentModifyController($field, true, false);
    $withoutNested->actionModify();
    expect($withoutNested->output)->toContain("Matched: 3\nWould modify / modified: 1");

    // Selecting the nested entry itself still excludes it.
    $selected = contentModifyController($field, true, false, (string)$f['nestedId']);
    $selected->actionModify();
    expect($selected->output)->toContain('Matched: 0');

    $write = contentModifyController($field, false, false);
    $write->actionModify();
    expect(contentModifyStored($f['nestedId'], $field)[0])->toHaveKey('type');
    expect(array_column(contentModifyStored($f['owner']->id, $field), 'linkTypeHandle'))->toBe(['url', 'entry']);

    Craft::$app->getDb()->createCommand()->delete('{{%hyper_links}}', ['fieldId' => $field->id])->execute();
    $sync = contentModifyController($field, false, false);
    expect($sync->actionSyncRelations())->toBe(0);
    expect(contentModifyRelationOwners($field))->toEqualCanonicalizing([$f['owner']->id, $f['current']->id]);
});

it('modify and sync-relations skip Neo blocks when nested content is excluded', function() {
    expect(Craft::$app->plugins->isPluginEnabled('neo'))->toBeTrue();

    $field = F::hyperField();
    $layout = new FieldLayout(['type' => Block::class]);
    $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new CustomField($field)]])]);
    $type = new BlockType(['name' => 'Links', 'handle' => 'links', 'sortOrder' => 1]);
    $type->setFieldLayout($layout);
    $neo = new NeoField(['name' => 'Neo', 'handle' => F::handle('hyperTestNeo')]);
    $neo->setBlockTypes([$type]);

    try {
        expect(Craft::$app->fields->saveField($neo))->toBeTrue();
        // Neo memoizes the unsaved block type while the field is created.
        unset(Memoize::$blockTypesByFieldId[$neo->id]);
        $neo = Craft::$app->fields->getFieldById($neo->id);
        $section = F::entrySectionWithField($neo);
        $target = F::plainEntry($section, 'Target');
        $owner = F::plainEntry($section, 'Neo owner', [$neo->handle => ['new1' => [
            'type' => 'links',
            'enabled' => true,
            'level' => 1,
            'fields' => [$field->handle => [F::entryLinkPayload($target, 'Target')]],
        ]]]);
        $blockId = $owner->getFieldValue($neo->handle)->one()->id;

        $withNested = contentModifyController($field, true, true);
        $withNested->actionModify();
        expect($withNested->output)->not->toContain('Matched: 0');

        $withoutNested = contentModifyController($field, true, false);
        $withoutNested->actionModify();
        expect($withoutNested->output)->toContain('Matched: 0');

        Craft::$app->getDb()->createCommand()->delete('{{%hyper_links}}', ['fieldId' => $field->id])->execute();
        $sync = contentModifyController($field, false, false);
        $sync->actionSyncRelations();
        expect(contentModifyRelationOwners($field))->toBe([]);

        $sync = contentModifyController($field, false, true);
        $sync->actionSyncRelations();
        expect(contentModifyRelationOwners($field))->toBe([$blockId]);
    } finally {
        if ($neo?->id) {
            Craft::$app->fields->deleteField($neo);
            unset(Memoize::$blockTypesByFieldId[$neo->id]);
        }
    }
});
