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
