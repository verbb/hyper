<?php

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\links\Asset;
use verbb\hyper\links\Url;

beforeEach(function() {
    $handle = F::handle('upload');
    $fs = new craft\fs\Local(['name' => $handle, 'handle' => $handle, 'path' => sys_get_temp_dir() . '/' . $handle]);
    expect(Craft::$app->fs->saveFilesystem($fs))->toBeTrue();
    $volume = new craft\models\Volume(['name' => $handle, 'handle' => $handle, 'fsHandle' => $handle]);
    expect(Craft::$app->volumes->saveVolume($volume))->toBeTrue();
    $user = new User(['username' => $handle, 'email' => $handle . '@example.test', 'pending' => false]);
    expect(Craft::$app->elements->saveElement($user))->toBeTrue();
    $this->uploadPermissions = Craft::$app->userPermissions;
    Craft::$app->set('userPermissions', new craft\services\UserPermissions());
    $this->uploadFixture = compact('volume', 'user');
});

afterEach(function() {
    Craft::$app->set('userPermissions', $this->uploadPermissions);
});

function assetUploadInputSettings(Asset $link, User $user): array
{
    return CpActionRequest::asUser($user, function() use ($link) {
        $view = Craft::$app->view;
        $view->startJsBuffer();
        try {
            $html = $link->getInputHtml(new LinkField(), F::hyperField());
        } finally {
            $js = $view->clearJsBuffer();
        }
        preg_match('/new Craft\\.AssetSelectInput\\((?:[^,{]+,\s*)?(\{[^\n]+\})\);/', $js, $matches);
        expect($matches)->toHaveCount(2);
        return [Json::decode($matches[1]), $html];
    });
}

it('connects asset upload controls to the configured folder for root and nested links', function(bool $nested, string $subpath) {
    ['volume' => $volume, 'user' => $user] = $this->uploadFixture;
    Craft::$app->userPermissions->saveUserPermissions($user->id, ['viewAssets:' . $volume->uid, 'saveAssets:' . $volume->uid]);
    $user = User::find()->id($user->id)->one();
    $owner = F::plainEntry(F::entrySection(), 'Upload owner');
    $link = new Asset(['defaultUploadLocationSource' => 'volume:' . $volume->uid, 'defaultUploadLocationSubpath' => $subpath]);
    if ($nested) {
        $parent = new Url();
        $parent->setOwner($owner);
        $link->setOwner($parent);
    } else {
        $link->setOwner($owner);
    }
    [$settings] = assetUploadInputSettings($link, $user);
    expect($settings['canUpload'])->toBeTrue()->and($settings['criteria']['siteId'])->toBe($owner->siteId);
    expect($settings)->toHaveKey('uploadFolderId');
    $folder = Craft::$app->assets->getFolderById($settings['uploadFolderId']);
    expect($folder->volumeId)->toBe($volume->id)
        ->and($folder->path)->toBe($subpath ? 'documents/' . $owner->slug . '/' : null)
        ->and($settings['fsType'])->toBe(craft\fs\Local::class);
})->with([false, true])->with(['', 'documents/{slug}']);

it('does not offer direct uploads without permission to save assets', function() {
    ['volume' => $volume, 'user' => $user] = $this->uploadFixture;
    Craft::$app->userPermissions->saveUserPermissions($user->id, ['viewAssets:' . $volume->uid]);
    $user = User::find()->id($user->id)->one();
    $link = new Asset(['defaultUploadLocationSource' => 'volume:' . $volume->uid]);
    [$settings] = assetUploadInputSettings($link, $user);
    expect($settings['canUpload'])->toBeFalse()->and($settings)->not->toHaveKey('uploadFolderId');
});

it('explains unresolved upload paths without sending files to a temporary folder', function() {
    ['volume' => $volume, 'user' => $user] = $this->uploadFixture;
    Craft::$app->userPermissions->saveUserPermissions($user->id, ['viewAssets:' . $volume->uid, 'saveAssets:' . $volume->uid]);
    $user = User::find()->id($user->id)->one();
    $link = new Asset(['defaultUploadLocationSource' => 'volume:' . $volume->uid, 'defaultUploadLocationSubpath' => 'documents/{id}']);
    $link->setOwner(new Entry());
    [$settings, $html] = assetUploadInputSettings($link, $user);
    expect($settings['canUpload'])->toBeFalse()->and($settings)->not->toHaveKey('uploadFolderId')
        ->and($html)->toContain('upload location');
});
