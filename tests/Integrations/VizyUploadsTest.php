<?php
uses(Tests\General\TestCase::class);

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;

it('finalizes an upload inside Vizy inside Hyper content', function () {
    $handle = F::handle('hyperTestFs');
    $fs = new craft\fs\Local(['name' => $handle, 'handle' => $handle, 'path' => sys_get_temp_dir() . '/' . $handle]);
    expect(Craft::$app->fs->saveFilesystem($fs))->toBeTrue();
    $volume = new craft\models\Volume(['name' => $handle, 'handle' => $handle, 'fsHandle' => $handle]);
    expect(Craft::$app->volumes->saveVolume($volume))->toBeTrue();
    $assetField = new Assets(['name' => 'Upload', 'handle' => F::handle('hyperTestUpload'), 'defaultUploadLocationSource' => 'volume:' . $volume->uid]);
    expect(Craft::$app->fields->saveField($assetField))->toBeTrue();
    if (!Craft::$app->plugins->isPluginInstalled('vizy')) {
        Craft::$app->plugins->installPlugin('vizy');
    }
    $layout = new craft\models\FieldLayout(['type' => verbb\vizy\elements\Block::class]);
    $assetElement = new CustomField($assetField, ['uid' => StringHelper::UUID()]);
    $layout->setTabs([new craft\models\FieldLayoutTab(['layout' => $layout, 'name' => 'Content', 'elements' => [$assetElement]])]);
    expect(Craft::$app->fields->saveLayout($layout))->toBeTrue();
    $blockId = StringHelper::UUID();
    $inner = new verbb\vizy\fields\VizyField([
        'name' => 'Nested Vizy upload', 'handle' => F::handle('hyperTestVizy'),
        'fieldData' => [['id' => StringHelper::UUID(), 'blockTypes' => [[
            'id' => $blockId, 'name' => 'Upload', 'handle' => 'upload', 'enabled' => true,
            'icon' => ['label' => 'Link', 'value' => 'link'],
            'layoutUid' => $layout->uid, 'layoutConfig' => $layout->getConfig(),
        ]]]],
    ]);
    expect(Craft::$app->fields->saveField($inner))->toBeTrue();
    $outer = new Url();
    $outerLayout = Url::getDefaultFieldLayout();
    $outerTab = $outerLayout->getTabs()[0];
    $outerTab->setElements([...$outerTab->getElements(), new CustomField($inner, ['uid'=>StringHelper::UUID()])]);
    $outer->setFieldLayout($outerLayout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($outer)]);
    $folder = Craft::$app->assets->getUserTemporaryUploadFolder(User::find()->admin()->one());
    $path = tempnam(sys_get_temp_dir(), 'hyper-upload-');
    file_put_contents($path, 'Synthetic upload regression');
    $asset = new Asset(['tempFilePath' => $path, 'filename' => $handle . '.txt', 'newFolderId' => $folder->id]);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    expect(Craft::$app->elements->saveElement($asset))->toBeTrue();
    expect($asset->volumeId)->toBeNull();
    try {
        $node = ['type' => 'vizyBlock', 'attrs' => ['id' => StringHelper::UUID(), 'values' => [
            'type' => $blockId, 'enabled' => true, 'content' => ['fields' => [$assetElement->uid => [$asset->id]]],
        ]]];
        $owner = F::plainEntry(F::entrySection($field), 'Upload owner', [$field->handle => [[
            'handle' => 'url', 'linkValue' => 'https://example.test/upload', 'fields' => [$inner->handle => [$node]],
        ]]]);
        $reload = Entry::find()->id($owner->id)->one();
        $selected = $reload->getFieldValue($field->handle)->getLinks()[0]->getFieldValue($inner->handle)->getNodes()[0]->getFieldValue($assetField->handle)->one();
        expect($selected->id)->toBe($asset->id);
        expect($selected->volumeId)->toBe($volume->id);
        expect(Craft::$app->elements->saveElement($reload))->toBeTrue();
        expect(Asset::find()->id($asset->id)->one()->volumeId)->toBe($volume->id);
    } finally {
        Craft::$app->elements->deleteElementById($asset->id, Asset::class, hardDelete: true);
        Craft::$app->volumes->deleteVolume($volume);
        Craft::$app->fs->removeFilesystem($fs);
        @unlink($path);
        @rmdir(sys_get_temp_dir() . '/' . $handle);
    }
});
