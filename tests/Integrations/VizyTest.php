<?php

use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\Hyper;
use verbb\hyper\migrations\MigrateTypedLinkContent;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\records\LinkRelation;

uses(Tests\General\TestCase::class);

it('migrates actual Vizy array content without changing dry-run data or its storage format', function () {
    if (!Craft::$app->plugins->isPluginInstalled('vizy')) {
        Craft::$app->plugins->installPlugin('vizy');
    }
    expect(Craft::$app->plugins->isPluginInstalled('vizy'))->toBeTrue();
    $field = F::hyperField();
    $layout = new FieldLayout(['type' => verbb\vizy\elements\Block::class]);
    $fieldElement = new CustomField($field, ['uid' => StringHelper::UUID()]);
    $layout->setTabs([new FieldLayoutTab(['layout' => $layout, 'name' => 'Content', 'elements' => [$fieldElement]])]);
    expect(Craft::$app->fields->saveLayout($layout))->toBeTrue();
    $blockId = StringHelper::UUID();
    $vizyField = new verbb\vizy\fields\VizyField([
        'name' => 'Vizy acceptance', 'handle' => F::handle('hyperTestVizy'),
        'fieldData' => [['id' => StringHelper::UUID(), 'blockTypes' => [[
            'id' => $blockId, 'name' => 'Links', 'handle' => 'links', 'enabled' => true,
            'icon' => ['label' => 'Link', 'value' => 'link'],
            'layoutUid' => $layout->uid, 'layoutConfig' => $layout->getConfig(),
        ]]]],
    ]);
    expect(Craft::$app->fields->saveField($vizyField))->toBeTrue();
    $owner = F::plainEntry(F::entrySectionWithField($vizyField), 'Vizy owner');
    $hostUid = $owner->getFieldLayout()->getFieldByHandle($vizyField->handle)->layoutElement->uid;
    // A legacy field is embedded in real Vizy content under the Craft 5 layout-element UID.
    $nodes = [['type' => 'vizyBlock', 'attrs' => ['id' => StringHelper::UUID(), 'values' => [
        'type' => $blockId, 'enabled' => true,
        'content' => ['fields' => [$fieldElement->uid => ['type' => 'url', 'value' => 'https://example.test/legacy-vizy', 'customText' => 'Retained Vizy text']]],
    ]]]];
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([$hostUid => Json::encode($nodes)])], $where)->execute();
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $migration = new MigrateTypedLinkContent();
    $before = $read();
    Craft::$app->fields->refreshFields();
    $migration->dryRun = true;
    $migration->migrateVizyContent(['uid' => $field->uid, 'handle' => $field->handle], $field);
    expect($read())->toBe($before);
    $migration->dryRun = false;
    $migration->migrateVizyContent(['uid' => $field->uid, 'handle' => $field->handle], $field);
    $saved = Json::decode($read());
    expect($saved[$hostUid])->toBeString();
    $converted = Json::decode($saved[$hostUid])[0]['attrs']['values']['content']['fields'][$fieldElement->uid];
    expect($converted[0]['linkValue'])->toBe('https://example.test/legacy-vizy');
    expect($converted[0]['linkText'])->toBe('Retained Vizy text');
    $after = $read();
    $migration->migrateVizyContent(['uid' => $field->uid, 'handle' => $field->handle], $field);
    expect($read())->toBe($after);
    $target = F::plainEntry(F::entrySection(), 'Target');
    Hyper::$plugin->content->modify($field, fn() => new LinkCollection($field, [['handle' => 'entry', 'linkValue' => [$target->id]]]), new ModifyOptions(elementIds: [$owner->id]));
    expect(LinkRelation::find()->where(['ownerId' => $owner->id, 'fieldId' => $field->id])->count())->toBe(0);
});

it('converts and repairs Linkit and Link content in Vizy with the owning site', function(string $source, bool $direct) {
    if (!Craft::$app->plugins->isPluginInstalled('vizy')) {
        Craft::$app->plugins->installPlugin('vizy');
    }
    $field = F::hyperField();
    $layout = new FieldLayout(['type' => verbb\vizy\elements\Block::class]);
    $fieldElement = new CustomField($field, ['uid' => StringHelper::UUID()]);
    $layout->setTabs([new FieldLayoutTab(['layout' => $layout, 'name' => 'Content', 'elements' => [$fieldElement]])]);
    expect(Craft::$app->fields->saveLayout($layout))->toBeTrue();
    $blockId = StringHelper::UUID();
    $vizyField = new verbb\vizy\fields\VizyField([
        'name' => 'Vizy migration parity', 'handle' => F::handle('hyperTestVizy'),
        'fieldData' => [['id' => StringHelper::UUID(), 'blockTypes' => [[
            'id' => $blockId, 'name' => 'Links', 'handle' => 'links', 'enabled' => true,
            'icon' => ['label' => 'Link', 'value' => 'link'],
            'layoutUid' => $layout->uid, 'layoutConfig' => $layout->getConfig(),
        ]]]],
    ]);
    expect(Craft::$app->fields->saveField($vizyField))->toBeTrue();
    $owner = F::plainEntry(F::entrySectionWithField($vizyField), 'Vizy migration owner');
    $target = F::plainEntry(F::entrySection(), 'Vizy link target');
    $hostUid = $owner->getFieldLayout()->getFieldByHandle($vizyField->handle)->layoutElement->uid;
    $raw = $source === 'legacy-linkit'
        ? ['type' => 'entry', 'entry' => [$target->id], 'customText' => 'Retained text']
        : [['linkTypeHandle' => 'entry', 'linkValue' => $target->id, 'linkText' => 'Retained text', 'fields' => ['opaque' => ['keep' => true]]]];
    $nodes = [['type' => 'vizyBlock', 'attrs' => ['id' => StringHelper::UUID(), 'values' => [
        'type' => $blockId, 'enabled' => true, 'content' => ['fields' => [$fieldElement->uid => $raw]],
    ]]]];
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([$hostUid => Json::encode($nodes)])], $where)->execute();
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $migration = $source === 'repair-link'
        ? new \verbb\hyper\migrations\MigrateLinkContent()
        : new \verbb\hyper\migrations\MigrateLinkitContent();
    $migration->fields = [(new Query())->from('{{%fields}}')->where(['id' => $field->id])->one()];
    $run = fn() => $direct
        ? $migration->migrateVizyContent($migration->fields[0], $field)
        : $migration->processFieldContent();
    Craft::$app->fields->refreshFields();
    $before = $read();
    $migration->dryRun = true;
    $run();
    expect($read())->toBe($before);
    $migration->dryRun = false;
    $run();
    $saved = Json::decode($read());
    expect($saved[$hostUid])->toBeString();
    $converted = Json::decode($saved[$hostUid])[0]['attrs']['values']['content']['fields'][$fieldElement->uid];
    expect((array)$converted[0]['linkValue'])->toBe([$target->id]);
    expect($converted[0]['linkSiteId'])->toBe($owner->siteId);
    expect($converted[0]['linkText'])->toBe('Retained text');
    if ($source !== 'legacy-linkit') {
        $raw[0]['linkSiteId'] = $owner->siteId;
        expect($converted)->toEqual($raw);
    }
    $after = $read();
    $run();
    expect($read())->toBe($after);
    expect(LinkRelation::find()->where(['ownerId' => $owner->id, 'fieldId' => $field->id])->count())->toBe(0);
})->with(['legacy-linkit', 'repair-linkit', 'repair-link'])->with([false, true]);

function vizyRichTextNodes(): array
{
    return [
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'start'], 'content' => [
            ['type' => 'text', 'text' => 'Intro '],
            ['type' => 'text', 'text' => 'bold words', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'a link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test/mark', 'target' => null]]]],
        ]],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'start'], 'content' => [['type' => 'text', 'text' => 'Heading']]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item one']]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item two']]]]],
        ]],
    ];
}

it('locates Vizy 3 nested values without adding keys to the surrounding document', function() {
    $value = [['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/before', 'linkText' => 'Before']];
    $nodes = [...vizyRichTextNodes(), ['type' => 'vizyBlock', 'attrs' => ['id' => 'vizy-block', 'enabled' => true, 'values' => [
        'type' => 'block-type', 'content' => ['fields' => ['links' => $value]],
    ]]]];
    $document = $nodes;
    $locations = (new verbb\hyper\content\locators\VizyNestedFieldLocator())->locateInHostValue($document, 'links', 'host');

    expect($locations)->toHaveCount(1);
    expect($locations[0]->jsonPath)->toBe('host.3.attrs.values.content.fields.links');
    expect($locations[0]->value)->toBe($value);
    expect(Json::encode($document))->toBe(Json::encode($nodes));

    $locations[0]->value[0]['linkText'] = 'After';
    $nodes[3]['attrs']['values']['content']['fields']['links'][0]['linkText'] = 'After';

    expect(Json::encode($document))->toBe(Json::encode($nodes));
    expect(array_is_list($document[0]['content']))->toBeTrue();
    expect(array_is_list($document[0]['content'][1]['marks']))->toBeTrue();
});

it('modifies a Hyper value in a Vizy 3 block without rewriting the rest of the Vizy document', function() {
    if (!Craft::$app->plugins->isPluginInstalled('vizy')) {
        Craft::$app->plugins->installPlugin('vizy');
    }
    $field = F::hyperField();
    $layout = new FieldLayout(['type' => verbb\vizy\elements\Block::class]);
    $fieldElement = new CustomField($field, ['uid' => StringHelper::UUID()]);
    $layout->setTabs([new FieldLayoutTab(['layout' => $layout, 'name' => 'Content', 'elements' => [$fieldElement]])]);
    expect(Craft::$app->fields->saveLayout($layout))->toBeTrue();
    $blockId = StringHelper::UUID();
    $vizyField = new verbb\vizy\fields\VizyField([
        'name' => 'Vizy document preservation', 'handle' => F::handle('hyperTestVizy'),
        'fieldData' => [['id' => StringHelper::UUID(), 'blockTypes' => [[
            'id' => $blockId, 'name' => 'Links', 'handle' => 'links', 'enabled' => true,
            'icon' => ['label' => 'Link', 'value' => 'link'],
            'layoutUid' => $layout->uid, 'layoutConfig' => $layout->getConfig(),
        ]]]],
    ]);
    expect(Craft::$app->fields->saveField($vizyField))->toBeTrue();
    $owner = F::plainEntry(F::entrySectionWithField($vizyField), 'Vizy document owner');
    $hostUid = $owner->getFieldLayout()->getFieldByHandle($vizyField->handle)->layoutElement->uid;
    $nodes = [...vizyRichTextNodes(), ['type' => 'vizyBlock', 'attrs' => ['id' => StringHelper::UUID(), 'enabled' => true, 'collapsed' => false, 'values' => [
        'type' => $blockId,
        'content' => ['fields' => [$fieldElement->uid => [['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/before', 'linkText' => 'Before']]]],
    ]]]];
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([$hostUid => Json::encode($nodes)])], $where)->execute();
    $read = fn() => Json::decode((new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar())[$hostUid];
    Craft::$app->fields->refreshFields();

    $result = Hyper::$plugin->getContent()->modify($field, function(LinkCollection $collection) {
        $links = $collection->getLinks();
        $links[0]->linkText = 'After';

        return $collection->withLinks($links);
    }, new ModifyOptions(elementIds: [$owner->id]));

    expect($result->matched)->toBe(1);
    expect($result->modified)->toBe(1);
    $stored = $read();
    expect($stored)->toBeString()->not->toContain('"fields":null');
    $saved = Json::decode($stored);
    expect($saved[3]['attrs']['values']['content']['fields'][$fieldElement->uid][0]['linkText'])->toBe('After');

    // Everything outside the changed Hyper value keeps its original JSON.
    $saved[3]['attrs']['values']['content']['fields'][$fieldElement->uid] = $nodes[3]['attrs']['values']['content']['fields'][$fieldElement->uid];
    expect(Json::encode($saved))->toBe(Json::encode($nodes));

    $entry = craft\elements\Entry::find()->id($owner->id)->status(null)->one();
    expect($entry->getFieldValue($vizyField->handle)->all())->toHaveCount(4);
});
