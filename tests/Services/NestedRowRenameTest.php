<?php

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use benf\neo\helpers\Memoize;
use benf\neo\models\BlockType;
use benf\neo\Plugin as Neo;
use craft\base\Field;
use craft\behaviors\CustomFieldBehavior;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;
use verbb\hyper\migrations\m261125_000000_add_nested_row_identities as AddNestedRowIdentities;
use verbb\hyper\services\EmbeddedFields;
use yii\db\JsonExpression;

function nestedRenameLayout(string $type, array $fields): FieldLayout
{
    $layout = new FieldLayout(['type' => $type]);
    $layout->setTabs([new FieldLayoutTab([
        'name' => 'Content',
        'layout' => $layout,
        'elements' => array_map(fn($field) => new CustomField($field), $fields),
    ])]);

    return $layout;
}

function nestedRenameFixture(): array
{
    $fields = Craft::$app->getFields();
    $rowText = new PlainText(['name' => 'Row text', 'handle' => F::handle('rowText')]);
    $neoText = new PlainText(['name' => 'Neo text', 'handle' => F::handle('neoText')]);
    expect($fields->saveField($rowText))->toBeTrue()
        ->and($fields->saveField($neoText))->toBeTrue();

    $rowType = new EntryType(['name' => 'Row', 'handle' => F::handle('row'), 'hasTitleField' => false]);
    $rowType->setFieldLayout(nestedRenameLayout(Entry::class, [$rowText]));
    expect(Craft::$app->getEntries()->saveEntryType($rowType))->toBeTrue();

    $matrix = new Matrix(['name' => 'Rows', 'handle' => F::handle('rows')]);
    $matrix->setEntryTypes([$rowType]);
    expect($fields->saveField($matrix))->toBeTrue();

    $blockType = new BlockType(['name' => 'Block', 'handle' => F::handle('block'), 'sortOrder' => 1]);
    $blockType->setFieldLayout(nestedRenameLayout(Block::class, [$neoText]));
    $neo = new NeoField(['name' => 'Blocks', 'handle' => F::handle('blocks')]);
    $neo->setBlockTypes([$blockType]);
    expect($fields->saveField($neo))->toBeTrue();
    unset(Memoize::$blockTypesByFieldId[$neo->id]);

    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($fields->getFieldById($matrix->id)), new CustomField($fields->getFieldById($neo->id))]);
    $url->setFieldLayout($layout);
    $hyper = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)], [
        'multipleLinks' => true,
        'translationMethod' => Field::TRANSLATION_METHOD_SITE,
    ]);
    $section = F::translatableEntrySection($hyper);
    [$primarySite, $secondarySite] = F::ensureSites(2);

    $owner = F::plainEntry($section, 'Nested rename owner', [
        $hyper->handle => [[...F::urlLinkPayload('https://example.test/nested-rename'), 'fields' => [
            $matrix->handle => ['new1' => ['type' => $rowType->handle, 'enabled' => true, 'fields' => [$rowText->handle => 'matrix row']]],
            $neo->handle => ['new1' => ['type' => $blockType->handle, 'enabled' => true, 'level' => 1, 'fields' => [$neoText->handle => 'neo block']]],
        ]]],
    ], $primarySite);

    return [
        'hyper' => $hyper,
        'matrix' => $matrix,
        'neo' => $neo,
        'rowType' => $rowType,
        'blockType' => Neo::$plugin->blockTypes->getByFieldId($neo->id)[0],
        'rowText' => $rowText,
        'neoText' => $neoText,
        'ownerId' => $owner->id,
        'sites' => [$primarySite, $secondarySite],
    ];
}

function nestedRenameRefresh(): void
{
    // Start each read like a new request, so no layout keeps a renamed handle.
    $fields = Craft::$app->getFields();
    $fields->refreshFields();
    (new ReflectionProperty($fields, '_layouts'))->setValue($fields, null);
    Craft::$app->getEntries()->refreshEntryTypes();

    foreach (['blockTypeRecordsById', 'blockTypesById', 'blockTypesByHandle', 'blockTypesByFieldId', 'parentFieldInstancesByLayoutElementUuid'] as $cache) {
        Memoize::$$cache = [];
    }
}

function nestedRenameReload(array $fixture, int $siteId): Entry
{
    nestedRenameRefresh();

    return Entry::find()->id($fixture['ownerId'])->siteId($siteId)->status(null)->one();
}

/** @return array{matrix: list<?string>, neo: list<?string>} */
function nestedRenameRead(array $fixture, int $siteId): array
{
    $fields = Craft::$app->getFields();
    $rowText = $fields->getFieldById($fixture['rowText']->id)->handle;
    $neoText = $fields->getFieldById($fixture['neoText']->id)->handle;
    $link = nestedRenameReload($fixture, $siteId)->getFieldValue($fixture['hyper']->handle)->first();

    return [
        'matrix' => array_map(fn($row) => $row->getFieldValue($rowText), $link->getFieldValue($fixture['matrix']->handle)->all()),
        'neo' => array_map(fn($block) => $block->getFieldValue($neoText), $link->getFieldValue($fixture['neo']->handle)->all()),
    ];
}

function nestedRenameStored(array $fixture, int $siteId): string
{
    return (string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)
        ->where(['elementId' => $fixture['ownerId'], 'siteId' => $siteId])->scalar();
}

function nestedRenameSave(array $fixture, int $siteId): void
{
    $owner = nestedRenameReload($fixture, $siteId);
    // Mirrors a control-panel save, which posts the link field and marks it dirty.
    $owner->setFieldValue($fixture['hyper']->handle, $owner->getFieldValue($fixture['hyper']->handle));
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();
}

function nestedRenameField(int $fieldId, string $handle): void
{
    $field = Craft::$app->getFields()->getFieldById($fieldId);
    $oldHandle = $field->handle;
    $field->handle = $handle;
    expect(Craft::$app->getFields()->saveField($field))->toBeTrue();
    // A new request no longer knows the old handle at all.
    unset(CustomFieldBehavior::$fieldHandles[$oldHandle]);
    CustomFieldBehavior::$fieldHandles[$handle] = true;
    nestedRenameRefresh();
}

function nestedRenameTypes(array $fixture, string $rowHandle, string $blockHandle): void
{
    nestedRenameRefresh();
    $rowType = Craft::$app->getEntries()->getEntryTypeById($fixture['rowType']->id);
    $rowType->handle = $rowHandle;
    expect(Craft::$app->getEntries()->saveEntryType($rowType))->toBeTrue();

    $blockType = Neo::$plugin->blockTypes->getById($fixture['blockType']->id);
    $blockType->handle = $blockHandle;
    expect(Neo::$plugin->blockTypes->save($blockType))->toBeTrue();
    nestedRenameRefresh();
}

function nestedRenameStripIdentity(array $fixture, ?array $elementIds = null): void
{
    // Content saved before rows carried type and layout UIDs.
    $rows = (new Query())->select(['id', 'content'])->from(Table::ELEMENTS_SITES)
        ->where(['elementId' => $elementIds ?? [$fixture['ownerId']]])->andWhere(['not', ['content' => null]])->all();
    $strip = function(array $value) use (&$strip): array {
        unset($value['typeUid'], $value['fieldUids']);

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $strip($child);
            }
        }

        return $value;
    };

    foreach ($rows as $row) {
        Craft::$app->getDb()->createCommand()->update(Table::ELEMENTS_SITES, ['content' => new JsonExpression($strip(Json::decode($row['content'])))], [
            'id' => $row['id'],
        ])->execute();
    }
}

/** Mirrors a control-panel save: each nested field posts its rendered rows by `uid:`, with the values shown. */
function nestedRenameSubmit(array $fixture, int $siteId): void
{
    $owner = nestedRenameReload($fixture, $siteId);
    $collection = $owner->getFieldValue($fixture['hyper']->handle);
    $link = $collection->first();
    $fields = Craft::$app->getFields();
    $rowText = $fields->getFieldById($fixture['rowText']->id)->handle;
    $neoText = $fields->getFieldById($fixture['neoText']->id)->handle;
    $rows = $link->getFieldValue($fixture['matrix']->handle)->all();
    $blocks = $link->getFieldValue($fixture['neo']->handle)->all();
    $postedRows = [];
    $postedBlocks = [];

    foreach ($rows as $row) {
        $postedRows['uid:' . $row->uid] = ['type' => $row->getType()->handle, 'enabled' => true, 'fields' => [$rowText => (string)$row->getFieldValue($rowText)]];
    }

    foreach ($blocks as $block) {
        $postedBlocks['uid:' . $block->uid] = ['type' => $block->getType()->handle, 'enabled' => true, 'level' => 1, 'fields' => [$neoText => (string)$block->getFieldValue($neoText)]];
    }

    // The input replaces each posted field's whole raw value, so unrendered keys aren't carried over.
    $link->setFieldValue($fixture['matrix']->handle, ['entries' => $postedRows, 'sortOrder' => array_map(fn($row) => $row->uid, $rows)]);
    $link->setFieldValue($fixture['neo']->handle, ['blocks' => $postedBlocks, 'sortOrder' => array_map(fn($block) => $block->uid, $blocks)]);
    $owner->setFieldValue($fixture['hyper']->handle, $collection);
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();
}

function nestedRenameMigrate(): void
{
    nestedRenameRefresh();
    expect((new AddNestedRowIdentities(['compact' => true]))->safeUp())->toBeTrue();
    nestedRenameRefresh();
}

/** @return array<int, string> Stored content by `elements_sites` row ID. */
function nestedRenameStoredRows(array $elementIds): array
{
    $rows = (new Query())->select(['id', 'content'])->from(Table::ELEMENTS_SITES)
        ->where(['elementId' => $elementIds])->andWhere(['not', ['content' => null]])->orderBy('id')->all();

    return array_column($rows, 'content', 'id');
}

it('keeps Matrix and Neo rows inside links readable and saveable after nested field handle renames', function() {
    $fixture = nestedRenameFixture();
    [$primary, $secondary] = $fixture['sites'];
    $expected = ['matrix' => ['matrix row'], 'neo' => ['neo block']];
    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected);

    nestedRenameField($fixture['rowText']->id, F::handle('rowTextRenamed'));
    nestedRenameField($fixture['neoText']->id, F::handle('neoTextRenamed'));

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);

    nestedRenameSave($fixture, $secondary->id);
    nestedRenameSave($fixture, $primary->id);

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);
});

it('keeps Matrix and Neo rows inside links after entry type and block type handle renames', function() {
    $fixture = nestedRenameFixture();
    [$primary, $secondary] = $fixture['sites'];
    $expected = ['matrix' => ['matrix row'], 'neo' => ['neo block']];

    nestedRenameTypes($fixture, F::handle('rowRenamed'), F::handle('blockRenamed'));

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);

    // A save on one site propagates the link structure to the other.
    nestedRenameSave($fixture, $secondary->id);

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);
});

it('keeps unreadable legacy Matrix and Neo rows inside links until their handles are restored', function() {
    $fixture = nestedRenameFixture();
    [$primary, $secondary] = $fixture['sites'];
    $expected = ['matrix' => ['matrix row'], 'neo' => ['neo block']];
    nestedRenameStripIdentity($fixture);
    expect(nestedRenameStored($fixture, $primary->id))->not->toContain('fieldUids')
        ->and(nestedRenameRead($fixture, $primary->id))->toBe($expected);

    // Legacy rows hold only handles, so a renamed field can't be matched.
    // Reads and saves must still work, and the stored value must survive.
    $rowText = $fixture['rowText']->handle;
    $neoText = $fixture['neoText']->handle;
    nestedRenameField($fixture['rowText']->id, F::handle('rowTextRenamed'));
    nestedRenameField($fixture['neoText']->id, F::handle('neoTextRenamed'));
    expect(nestedRenameRead($fixture, $primary->id))->toBe(['matrix' => [null], 'neo' => [null]]);
    nestedRenameSave($fixture, $secondary->id);
    nestedRenameSave($fixture, $primary->id);
    nestedRenameField($fixture['rowText']->id, $rowText);
    nestedRenameField($fixture['neoText']->id, $neoText);

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);

    // The saves above wrote row identities, so strip them again for the type case.
    nestedRenameStripIdentity($fixture);
    $rowHandle = $fixture['rowType']->handle;
    $blockHandle = $fixture['blockType']->handle;
    nestedRenameTypes($fixture, F::handle('rowRenamed'), F::handle('blockRenamed'));
    expect(nestedRenameRead($fixture, $primary->id))->toBe(['matrix' => [], 'neo' => []]);
    nestedRenameSave($fixture, $secondary->id);
    nestedRenameSave($fixture, $primary->id);
    nestedRenameTypes($fixture, $rowHandle, $blockHandle);

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);
});

it('backfills row identities in links for every site, draft, revision and nested owner, so legacy rows survive a rename and an editor save', function() {
    $fixture = nestedRenameFixture();
    [$primary, $secondary] = $fixture['sites'];
    $expected = ['matrix' => ['matrix row'], 'neo' => ['neo block']];
    $userId = User::find()->admin()->one()->id;
    $owner = nestedRenameReload($fixture, $primary->id);
    $revisionId = Craft::$app->getRevisions()->createRevision($owner, $userId, force: true);
    $draftId = Craft::$app->getDrafts()->createDraft($owner, $userId, 'Nested rename draft')->id;

    // The same link field inside a top-level Matrix field, whose entries own their content.
    ['matrix' => $outer, 'blockEntryType' => $outerType] = F::matrixFieldWithHyper($fixture['hyper']);
    $nestedOwner = F::entryWithMatrixHyperLink(F::entrySectionWithField($outer), $outer, $fixture['hyper'], $outerType, [
        [...F::urlLinkPayload('https://example.test/nested-owner'), 'fields' => [
            $fixture['matrix']->handle => ['new1' => ['type' => $fixture['rowType']->handle, 'enabled' => true, 'fields' => [$fixture['rowText']->handle => 'nested owner row']]],
        ]],
    ]);
    $nestedIds = Entry::find()->ownerId($nestedOwner->id)->fieldId($outer->id)->status(null)->ids();
    expect($nestedIds)->toHaveCount(1);

    $elementIds = [$fixture['ownerId'], $revisionId, $draftId, ...$nestedIds];
    nestedRenameStripIdentity($fixture, $elementIds);
    $before = nestedRenameStoredRows($elementIds);
    $dates = (new Query())->select(['dateUpdated', 'id'])->from(Table::ELEMENTS)->where(['id' => $elementIds])->pairs();
    expect(count($before))->toBeGreaterThanOrEqual(7)
        ->and(implode('', $before))->not->toContain('fieldUids');

    nestedRenameMigrate();
    $after = nestedRenameStoredRows($elementIds);
    expect(array_keys($after))->toBe(array_keys($before));

    foreach ($after as $content) {
        expect($content)->toContain('"typeUid"')->toContain('"fieldUids"');
    }

    // Raw writes leave the elements as they were, and a second run finds nothing to change.
    expect((new Query())->select(['dateUpdated', 'id'])->from(Table::ELEMENTS)->where(['id' => $elementIds])->pairs())->toBe($dates);
    nestedRenameMigrate();
    expect(nestedRenameStoredRows($elementIds))->toBe($after);

    $rowText = $fixture['rowText']->handle;
    $neoText = $fixture['neoText']->handle;
    $rowTextRenamed = F::handle('rowTextRenamed');
    nestedRenameField($fixture['rowText']->id, $rowTextRenamed);
    nestedRenameField($fixture['neoText']->id, F::handle('neoTextRenamed'));

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);

    $revision = Entry::find()->id($revisionId)->revisions()->siteId($secondary->id)->status(null)->one();
    $nested = Entry::find()->id($nestedIds[0])->status(null)->one();
    expect($revision->getFieldValue($fixture['hyper']->handle)->first()->getFieldValue($fixture['matrix']->handle)->one()->getFieldValue($rowTextRenamed))->toBe('matrix row')
        ->and($nested->getFieldValue($fixture['hyper']->handle)->first()->getFieldValue($fixture['matrix']->handle)->one()->getFieldValue($rowTextRenamed))->toBe('nested owner row');

    nestedRenameSubmit($fixture, $primary->id);
    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);

    nestedRenameField($fixture['rowText']->id, $rowText);
    nestedRenameField($fixture['neoText']->id, $neoText);

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);
});

it('backfills row identities for link fields inside Matrix rows in links', function() {
    $fixture = nestedRenameFixture();
    [$primary] = $fixture['sites'];
    $fields = Craft::$app->getFields();
    $innerText = new PlainText(['name' => 'Inner text', 'handle' => F::handle('innerText')]);
    expect($fields->saveField($innerText))->toBeTrue();
    $innerType = new EntryType(['name' => 'Inner row', 'handle' => F::handle('innerRow'), 'hasTitleField' => false]);
    $innerType->setFieldLayout(nestedRenameLayout(Entry::class, [$innerText]));
    expect(Craft::$app->getEntries()->saveEntryType($innerType))->toBeTrue();
    $innerMatrix = new Matrix(['name' => 'Inner rows', 'handle' => F::handle('innerRows')]);
    $innerMatrix->setEntryTypes([$innerType]);
    expect($fields->saveField($innerMatrix))->toBeTrue();

    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($fields->getFieldById($innerMatrix->id))]);
    $url->setFieldLayout($layout);
    $inner = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    F::attachFieldToEntryType(Craft::$app->getEntries()->getEntryTypeById($fixture['rowType']->id), $inner);

    $owner = nestedRenameReload($fixture, $primary->id);
    $owner->setFieldValue($fixture['hyper']->handle, [[...F::urlLinkPayload('https://example.test/outer'), 'fields' => [
        $fixture['matrix']->handle => ['new1' => ['type' => $fixture['rowType']->handle, 'enabled' => true, 'fields' => [
            $fixture['rowText']->handle => 'outer row',
            $inner->handle => [[...F::urlLinkPayload('https://example.test/inner'), 'fields' => [
                $innerMatrix->handle => ['new1' => ['type' => $innerType->handle, 'enabled' => true, 'fields' => [$innerText->handle => 'inner row']]],
            ]]],
        ]]],
    ]]]);
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();
    expect(substr_count(nestedRenameStored($fixture, $primary->id), '"fieldUids"'))->toBe(2);

    // The content API can't reach link fields stored inside other rows, so the migration walks them itself.
    nestedRenameStripIdentity($fixture);
    expect(nestedRenameStored($fixture, $primary->id))->not->toContain('fieldUids');
    nestedRenameMigrate();
    expect(substr_count(nestedRenameStored($fixture, $primary->id), '"fieldUids"'))->toBe(2);

    $innerTextRenamed = F::handle('innerTextRenamed');
    nestedRenameField($innerText->id, $innerTextRenamed);
    $row = nestedRenameReload($fixture, $primary->id)->getFieldValue($fixture['hyper']->handle)->first()->getFieldValue($fixture['matrix']->handle)->one();
    $innerRow = $row->getFieldValue($inner->handle)->first()->getFieldValue($innerMatrix->handle)->one();

    expect($row->getFieldValue($fixture['rowText']->handle))->toBe('outer row')
        ->and($innerRow->getFieldValue($innerTextRenamed))->toBe('inner row');
});

it('keeps legacy rows inside links through editor saves when their handles were renamed before the migration ran', function() {
    $fixture = nestedRenameFixture();
    [$primary, $secondary] = $fixture['sites'];
    $expected = ['matrix' => ['matrix row'], 'neo' => ['neo block']];
    nestedRenameStripIdentity($fixture);

    // These rows can't be resolved, so editors see empty values. Saving must not lose the stored ones.
    $rowText = $fixture['rowText']->handle;
    $neoText = $fixture['neoText']->handle;
    nestedRenameField($fixture['rowText']->id, F::handle('rowTextRenamed'));
    nestedRenameField($fixture['neoText']->id, F::handle('neoTextRenamed'));
    expect(nestedRenameRead($fixture, $primary->id))->toBe(['matrix' => [null], 'neo' => [null]]);
    nestedRenameSubmit($fixture, $primary->id);
    nestedRenameSubmit($fixture, $secondary->id);
    nestedRenameField($fixture['rowText']->id, $rowText);
    nestedRenameField($fixture['neoText']->id, $neoText);

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);

    // Editors can't see rows of a renamed type at all, so they post none.
    nestedRenameStripIdentity($fixture);
    $rowHandle = $fixture['rowType']->handle;
    $blockHandle = $fixture['blockType']->handle;
    nestedRenameTypes($fixture, F::handle('rowRenamed'), F::handle('blockRenamed'));
    expect(nestedRenameRead($fixture, $primary->id))->toBe(['matrix' => [], 'neo' => []]);
    nestedRenameSubmit($fixture, $primary->id);
    nestedRenameTypes($fixture, $rowHandle, $blockHandle);

    expect(nestedRenameRead($fixture, $primary->id))->toBe($expected)
        ->and(nestedRenameRead($fixture, $secondary->id))->toBe($expected);
});

it('starts kept Neo blocks of an unreadable type at the top level, keeping their nesting under each other', function() {
    $fixture = nestedRenameFixture();
    $neo = Craft::$app->getFields()->getFieldById($fixture['neo']->id);
    $kept = EmbeddedFields::restoreUnreadableRows($neo, [
        'new1' => ['type' => $fixture['blockType']->handle, 'uid' => 'a', 'level' => 1, 'fields' => []],
    ], [
        ['type' => 'missingType', 'uid' => 'b', 'level' => 2, 'fields' => []],
        ['type' => 'missingType', 'uid' => 'c', 'level' => 3, 'fields' => []],
        ['type' => 'missingType', 'uid' => 'd', 'level' => 2, 'fields' => []],
    ]);

    expect(array_column($kept, 'level', 'uid'))->toBe(['a' => 1, 'b' => 1, 'c' => 2, 'd' => 1]);
});

it('drops Matrix and Neo rows inside links once their entry type or block type is removed from the field', function() {
    $fixture = nestedRenameFixture();
    [$primary] = $fixture['sites'];
    $fields = Craft::$app->getFields();
    $otherType = new EntryType(['name' => 'Other row', 'handle' => F::handle('otherRow'), 'hasTitleField' => false]);
    $otherType->setFieldLayout(nestedRenameLayout(Entry::class, [$fixture['rowText']]));
    expect(Craft::$app->getEntries()->saveEntryType($otherType))->toBeTrue();
    $matrix = $fields->getFieldById($fixture['matrix']->id);
    $matrix->setEntryTypes([$otherType]);
    expect($fields->saveField($matrix))->toBeTrue();
    $otherBlock = new BlockType(['fieldId' => $fixture['neo']->id, 'name' => 'Other block', 'handle' => F::handle('otherBlock'), 'sortOrder' => 2]);
    $otherBlock->setFieldLayout(nestedRenameLayout(Block::class, [$fixture['neoText']]));
    expect(Neo::$plugin->blockTypes->save($otherBlock))->toBeTrue()
        ->and(Neo::$plugin->blockTypes->delete(Neo::$plugin->blockTypes->getById($fixture['blockType']->id)))->toBeTrue();
    nestedRenameRefresh();

    expect(nestedRenameRead($fixture, $primary->id))->toBe(['matrix' => [], 'neo' => []]);
    nestedRenameSave($fixture, $primary->id);

    // The rows recorded their type UIDs, so a removed type isn't mistaken for a renamed one. Adding the
    // types back, or a new block type with the same handle, doesn't bring the rows back.
    $matrix = $fields->getFieldById($fixture['matrix']->id);
    $matrix->setEntryTypes([Craft::$app->getEntries()->getEntryTypeById($fixture['rowType']->id), $otherType]);
    expect($fields->saveField($matrix))->toBeTrue();
    $newBlock = new BlockType(['fieldId' => $fixture['neo']->id, 'name' => 'Block', 'handle' => $fixture['blockType']->handle, 'sortOrder' => 3]);
    $newBlock->setFieldLayout(nestedRenameLayout(Block::class, [$fixture['neoText']]));
    expect(Neo::$plugin->blockTypes->save($newBlock))->toBeTrue();
    nestedRenameRefresh();

    expect(nestedRenameStored($fixture, $primary->id))->not->toContain('matrix row')->not->toContain('neo block')
        ->and(nestedRenameRead($fixture, $primary->id))->toBe(['matrix' => [], 'neo' => []]);
});

it('keeps each link\'s own unreadable rows when two links in a field share a uid', function() {
    $fixture = nestedRenameFixture();
    [$primary] = $fixture['sites'];
    $uid = StringHelper::UUID();
    $payload = fn(string $text) => [...F::urlLinkPayload("https://example.test/$text"), 'uid' => $uid, 'fields' => [
        $fixture['matrix']->handle => ['new1' => ['type' => $fixture['rowType']->handle, 'enabled' => true, 'fields' => [$fixture['rowText']->handle => "$text row"]]],
        $fixture['neo']->handle => ['new1' => ['type' => $fixture['blockType']->handle, 'enabled' => true, 'level' => 1, 'fields' => [$fixture['neoText']->handle => "$text block"]]],
    ]];
    $read = function() use ($fixture, $primary): array {
        return array_map(fn($link) => [
            array_map(fn($row) => $row->getFieldValue($fixture['rowText']->handle), $link->getFieldValue($fixture['matrix']->handle)->all()),
            array_map(fn($block) => $block->getFieldValue($fixture['neoText']->handle), $link->getFieldValue($fixture['neo']->handle)->all()),
        ], nestedRenameReload($fixture, $primary->id)->getFieldValue($fixture['hyper']->handle)->getLinks());
    };
    $owner = nestedRenameReload($fixture, $primary->id);
    $owner->setFieldValue($fixture['hyper']->handle, [$payload('one'), $payload('two')]);
    expect(Craft::$app->getElements()->saveElement($owner))->toBeTrue();
    $expected = [[['one row'], ['one block']], [['two row'], ['two block']]];
    expect($read())->toBe($expected);

    // Legacy rows of a renamed type are unreadable, so the save restores them from stored content.
    nestedRenameStripIdentity($fixture);
    nestedRenameTypes($fixture, F::handle('rowRenamed'), F::handle('blockRenamed'));
    nestedRenameSave($fixture, $primary->id);
    nestedRenameTypes($fixture, $fixture['rowType']->handle, $fixture['blockType']->handle);

    expect($read())->toBe($expected);
});
