<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\CpInputContext;
use verbb\hyper\links\Url;
use yii\web\ForbiddenHttpException;

it('finalizes a temporary custom-field upload when its Hyper owner is saved', function () {
    $handle = F::handle('hyperTestFs');
    $fs = new craft\fs\Local(['name' => $handle, 'handle' => $handle, 'path' => sys_get_temp_dir() . '/' . $handle]);
    expect(Craft::$app->fs->saveFilesystem($fs))->toBeTrue();
    $volume = new craft\models\Volume(['name' => $handle, 'handle' => $handle, 'fsHandle' => $handle]);
    expect(Craft::$app->volumes->saveVolume($volume))->toBeTrue();
    $assetField = new Assets(['name' => 'Upload', 'handle' => F::handle('hyperTestUpload'), 'defaultUploadLocationSource' => 'volume:' . $volume->uid]);
    expect(Craft::$app->fields->saveField($assetField))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tabs = $layout->getTabs();
    $tabs[0]->setElements([...$tabs[0]->getElements(), new CustomField($assetField, ['uid' => StringHelper::UUID()])]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $folder = Craft::$app->assets->getUserTemporaryUploadFolder(User::find()->admin()->one());
    $path = tempnam(sys_get_temp_dir(), 'hyper-upload-');
    file_put_contents($path, 'Synthetic upload regression');
    $asset = new Asset(['tempFilePath' => $path, 'filename' => $handle . '.txt', 'newFolderId' => $folder->id]);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    expect(Craft::$app->elements->saveElement($asset))->toBeTrue();
    expect($asset->volumeId)->toBeNull();
    try {
        $owner = F::plainEntry(F::entrySection($field), 'Upload owner', [$field->handle => [['handle' => 'url', 'linkValue' => 'https://example.test/upload', 'fields' => [$assetField->handle => [$asset->id]]]]]);
        $reload = Entry::find()->id($owner->id)->one();
        $selected = $reload->getFieldValue($field->handle)->getLinks()[0]->getFieldValue($assetField->handle)->one();
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
