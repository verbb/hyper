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

it('namespaces native Matrix constructor IDs and all input namespace settings', function () {
    $method = new ReflectionMethod(HyperField::class, '_namespaceDeferredFieldPayload');
    $result = $method->invoke(new HyperField(), '<script>new Craft.MatrixInput("hyperData-row-fields-blocks", [], "hyperData[row][fields][blocks]", {"namespace":"hyperData[row][fields]"}); $("#hyperData-row-control");</script>');
    expect($result)->toContain('new Craft.Hyper.MatrixInput("fields-hyperData-row-fields-blocks"')
        ->toContain('"fields[hyperData][row][fields][blocks]"')
        ->toContain('"namespace":"fields[hyperData][row][fields]"')
        ->toContain('$("#fields-hyperData-row-control")');
});

it('binds signed editor context to user field site and original owner', function () {
    $field = F::hyperField();
    $owner = F::plainEntry(F::entrySection($field));
    $originalRequest = Craft::$app->getRequest();
    $user = Craft::$app->getUser();
    $originalIdentity = $user->getIdentity();
    $identity = User::find()->admin()->one();
    $request = new craft\web\Request(['isCpRequest' => true, 'isConsoleRequest' => false]);
    Craft::$app->set('request', $request);
    $user->setIdentity($identity);
    try {
        $token = CpInputContext::create($field, $owner);
        $context = CpInputContext::validate($token, $field->id, $owner->siteId, null);
        expect($context['ownerId'])->toBe($owner->id);
        foreach ([[$field->id + 1, $owner->siteId, null], [$field->id, $owner->siteId + 1, null], [$field->id, $owner->siteId, $owner->id + 1]] as $args) {
            expect(fn() => CpInputContext::validate($token, ...$args))->toThrow(ForbiddenHttpException::class);
        }
        expect(fn() => CpInputContext::validate($token . 'tampered', $field->id, $owner->siteId, null))->toThrow(ForbiddenHttpException::class);
        $user->setIdentity(null);
        expect(fn() => CpInputContext::validate($token, $field->id, $owner->siteId, null))->toThrow(ForbiddenHttpException::class);
    } finally {
        $user->setIdentity($originalIdentity);
        Craft::$app->set('request', $originalRequest);
    }
});

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

it('keeps canonical content unchanged until a saved Hyper draft is published', function () {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $owner = F::plainEntry(F::entrySection($field), 'Draft owner', [$field->handle => [['handle' => 'url', 'linkValue' => 'https://example.test/original']]]);
    $draft = Craft::$app->drafts->createDraft($owner, User::find()->admin()->one()->id, 'Acceptance draft');
    $draft->setFieldValue($field->handle, [['handle' => 'url', 'linkValue' => 'https://example.test/draft']]);
    expect(Craft::$app->elements->saveElement($draft))->toBeTrue();
    expect(Entry::find()->id($owner->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('https://example.test/original');
    $reload = Entry::find()->id($draft->id)->drafts(true)->status(null)->one();
    $published = Craft::$app->drafts->applyDraft($reload);
    expect(Entry::find()->id($published->id)->one()->getFieldValue($field->handle)->getUrl())->toBe('https://example.test/draft');
});

it('finalizes an upload inside nested Hyper content', function () {
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
    $inner = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
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
        $owner = F::plainEntry(F::entrySection($field), 'Upload owner', [$field->handle => [['handle' => 'url', 'linkValue' => 'https://example.test/upload', 'fields' => [$inner->handle => [['handle'=>'url','linkValue'=>'https://example.test/inner','fields'=>[$assetField->handle=>[$asset->id]]]]]]]]);
        $reload = Entry::find()->id($owner->id)->one();
        $selected = $reload->getFieldValue($field->handle)->getLinks()[0]->getFieldValue($inner->handle)->first()->getFieldValue($assetField->handle)->one();
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
