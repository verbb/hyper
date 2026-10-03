<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\conditions\assets\SavableConditionRule;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Url;
use yii\web\ForbiddenHttpException;

beforeEach(function() {
    [$site, $otherSite] = F::ensureSites(2);
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $section = F::translatableEntrySection($field, 2);
    $owner = F::plainEntry($section, 'Authorised owner');
    $otherSection = F::entrySection();
    $otherOwner = F::plainEntry($otherSection, 'Private owner');
    // Craft caches its permission tree; newly created fixture sections need a fresh tree.
    $this->originalPermissions = Craft::$app->userPermissions;
    Craft::$app->set('userPermissions', new \craft\services\UserPermissions());
    $users = [];
    foreach (['editor', 'otherEditor', 'noSite', 'noOwner'] as $role) {
        $handle = F::handle('hyperPermission');
        $user = new User(['username' => $handle, 'email' => $handle . '@example.test', 'pending' => false]);
        expect(Craft::$app->elements->saveElement($user))->toBeTrue();
        $permissions = ['accessCp'];
        if ($role !== 'noSite') {
            $permissions[] = 'editSite:' . $site->uid;
        }
        if ($role !== 'noOwner') {
            foreach (['viewEntries', 'viewPeerEntries', 'saveEntries', 'savePeerEntries'] as $permission) {
                $permissions[] = $permission . ':' . $section->uid;
            }
        }
        Craft::$app->userPermissions->saveUserPermissions($user->id, $permissions);
        $users[$role] = User::find()->id($user->id)->status(null)->one();
    }
    $this->cpFixture = compact('field', 'owner', 'otherOwner', 'site', 'otherSite', 'users');
});

it('finalizes only temporary uploads owned by the current control-panel user', function() {
    $f = $this->cpFixture;
    $handle = F::handle('hyperPermissionFs');
    $path = sys_get_temp_dir() . '/' . $handle;
    $fs = new craft\fs\Local(['name' => $handle, 'handle' => $handle, 'path' => $path]);
    expect(Craft::$app->fs->saveFilesystem($fs))->toBeTrue();
    $volume = new craft\models\Volume(['name' => $handle, 'handle' => $handle, 'fsHandle' => $handle]);
    expect(Craft::$app->volumes->saveVolume($volume))->toBeTrue();
    $assetField = new Assets([
        'name' => 'Upload',
        'handle' => F::handle('hyperPermissionUpload'),
        'defaultUploadLocationSource' => 'volume:' . $volume->uid,
        'restrictFiles' => true,
        'allowedKinds' => [Asset::KIND_TEXT],
    ]);
    $condition = Asset::createCondition();
    $condition->setConditionRules([$condition->createConditionRule([
        'class' => SavableConditionRule::class,
        'value' => true,
    ])]);
    $assetField->setSelectionCondition($condition);
    expect(Craft::$app->fields->saveField($assetField))->toBeTrue();

    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($assetField, ['uid' => StringHelper::UUID()])]);
    $url->setFieldLayout($layout);
    $f['field']->setLinkTypes([F::linkTypeConfig($url)]);
    expect(Craft::$app->fields->saveField($f['field']))->toBeTrue();

    Craft::$app->set('userPermissions', new \craft\services\UserPermissions());
    $current = $f['users']['editor'];
    $permissions = Craft::$app->userPermissions->getPermissionsByUserId($current->id);
    expect(Craft::$app->userPermissions->saveUserPermissions($current->id, [
        ...$permissions,
        'viewAssets:' . $volume->uid,
        'saveAssets:' . $volume->uid,
    ]))->toBeTrue();

    $admin = User::find()->admin()->one();
    $foreignPath = tempnam(sys_get_temp_dir(), 'hyper-foreign-upload-');
    file_put_contents($foreignPath, 'Foreign temporary upload');
    $foreignFolder = Craft::$app->assets->getUserTemporaryUploadFolder($f['users']['otherEditor']);
    $foreign = new Asset([
        'tempFilePath' => $foreignPath,
        'filename' => $handle . '-foreign.txt',
        'newFolderId' => $foreignFolder->id,
        'uploaderId' => $f['users']['otherEditor']->id,
    ]);
    $foreign->setScenario(Asset::SCENARIO_CREATE);
    expect(Craft::$app->elements->saveElement($foreign))->toBeTrue();

    $ownPath = tempnam(sys_get_temp_dir(), 'hyper-own-upload-');
    file_put_contents($ownPath, 'Current user temporary upload');
    $ownFolder = Craft::$app->assets->getUserTemporaryUploadFolder($current);
    $own = new Asset([
        'tempFilePath' => $ownPath,
        'filename' => $handle . '-own.txt',
        'newFolderId' => $ownFolder->id,
        'uploaderId' => $current->id,
    ]);
    $own->setScenario(Asset::SCENARIO_CREATE);
    expect(Craft::$app->elements->saveElement($own))->toBeTrue();

    $disallowedPath = tempnam(sys_get_temp_dir(), 'hyper-disallowed-upload-');
    file_put_contents($disallowedPath, '{}');
    $disallowed = new Asset([
        'tempFilePath' => $disallowedPath,
        'filename' => $handle . '-disallowed.json',
        'newFolderId' => $ownFolder->id,
        'uploaderId' => $current->id,
    ]);
    $disallowed->setScenario(Asset::SCENARIO_CREATE);
    expect(Craft::$app->elements->saveElement($disallowed))->toBeTrue();

    try {
        $owner = Entry::find()->id($f['owner']->id)->status(null)->one();
        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'url',
            'linkValue' => 'https://example.test/foreign-upload',
            'fields' => [$assetField->handle => [$foreign->id]],
        ]]);
        expect(fn() => CpActionRequest::asUser($admin, fn() => Craft::$app->elements->saveElement($owner, false)))
            ->toThrow(ForbiddenHttpException::class, 'User is not authorized to finalize a selected temporary asset.');
        expect(Asset::find()->id($foreign->id)->one()->volumeId)->toBeNull();

        $owner = Entry::find()->id($f['owner']->id)->status(null)->one();
        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'url',
            'linkValue' => 'https://example.test/disallowed-upload',
            'fields' => [$assetField->handle => [$disallowed->id]],
        ]]);
        expect(fn() => CpActionRequest::asUser($current, fn() => Craft::$app->elements->saveElement($owner, false)))
            ->toThrow(ForbiddenHttpException::class, 'The selected temporary asset is not permitted by this Assets field.');
        expect(Asset::find()->id($disallowed->id)->one()->volumeId)->toBeNull();

        $owner = Entry::find()->id($f['owner']->id)->status(null)->one();
        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'url',
            'linkValue' => 'https://example.test/own-upload',
            'fields' => [$assetField->handle => [$own->id]],
        ]]);
        expect(CpActionRequest::asUser($current, fn() => Craft::$app->elements->saveElement($owner, false)))->toBeTrue();
        expect(Asset::find()->id($own->id)->one()->volumeId)->toBe($volume->id);
    } finally {
        foreach ([$foreign, $own, $disallowed] as $asset) {
            if (Asset::find()->id($asset->id)->status(null)->one()) {
                Craft::$app->elements->deleteElementById($asset->id, Asset::class, hardDelete: true);
            }
        }
        Craft::$app->fields->deleteField($assetField);
        Craft::$app->volumes->deleteVolume($volume);
        Craft::$app->fs->removeFilesystem($fs);
        @unlink($foreignPath);
        @unlink($ownPath);
        @unlink($disallowedPath);
        @rmdir($path);
    }
});

afterEach(function() {
    if (isset($this->originalPermissions)) {
        Craft::$app->set('userPermissions', $this->originalPermissions);
    }
    foreach ($this->cpFixture['users'] ?? [] as $user) {
        Craft::$app->elements->deleteElement($user, true);
    }
});

function signedCpBody(array $fixture, string $role = 'editor', array $context = []): array
{
    $field = $fixture['field'];
    $owner = $fixture['owner'];
    // Generate a correctly signed context, including deliberately expired/swapped cases.
    // Permission enforcement still uses real persisted Craft users and grants.
    $data = array_replace([
        'userId' => $fixture['users'][$role]->id, 'fieldId' => $field->id,
        'siteId' => $owner->siteId, 'ownerId' => $owner->id, 'expires' => time() + 3600,
    ], $context);
    return [
        'fieldId' => $field->id, 'siteId' => $owner->siteId, 'elementId' => $owner->id,
        'inputContext' => Craft::$app->security->hashData(Json::encode($data)),
        'handle' => 'url', 'mode' => 'values', 'values' => ['https://example.test/allowed'],
        'data' => ['handle' => 'url', 'linkValue' => 'https://example.test/allowed'],
    ];
}

it('requires an enabled Embed type for preview requests', function() {
    $f = $this->cpFixture;
    $f['field']->setLinkTypes([
        F::linkTypeConfig(Url::class),
        F::linkTypeConfig(new \verbb\hyper\links\Embed(['allowedDomains' => ['allowed.example.test']])),
        F::linkTypeConfig(new \verbb\hyper\links\Embed(['handle' => 'disabledEmbed']), false),
    ]);
    expect(Craft::$app->fields->saveField($f['field']))->toBeTrue();
    // A loopback destination keeps this regression independent of public network I/O.
    $body = signedCpBody($f) + ['value' => 'http://127.0.0.1/'];
    foreach (['', 'url', 'unknown', 'disabledEmbed'] as $handle) {
        expect(fn() => CpActionRequest::run('preview-embed', $f['users']['editor'], $body + ['linkTypeHandle' => $handle]))
            ->toThrow(\yii\web\NotFoundHttpException::class);
    }
    $response = CpActionRequest::run('preview-embed', $f['users']['editor'], $body + ['linkTypeHandle' => 'embed']);
    expect($response->data['message'])->toBe('URL domain not allowed.');
});

it('rejects conflicting query and body authorization contexts', function(string $parameter) {
    $f = $this->cpFixture;
    $body = signedCpBody($f);
    $query = [$parameter => $body[$parameter]];
    $body[$parameter] = $parameter === 'fieldId' ? F::hyperField(['linkTypes' => [Url::class]])->id : $f['otherSite']->id;
    expect(fn() => CpActionRequest::run('create-links', $f['users']['editor'], $body, query: $query))
        ->toThrow(ForbiddenHttpException::class);
})->with(['fieldId', 'siteId']);

it('accepts a permitted editor and rejects invalid contexts through the public action lifecycle', function() {
    $f = $this->cpFixture;
    $body = signedCpBody($f);
    $allowed = CpActionRequest::run('input-settings-save', $f['users']['editor'], $body);
    expect($allowed->statusCode)->toBe(200);
    expect($allowed->data['values'])->toBe($body['values']);
    $duplicates = array_map('strval', array_intersect_key($body, array_flip(['fieldId', 'siteId', 'elementId', 'inputContext'])));
    expect(CpActionRequest::run('input-settings-save', $f['users']['editor'], $body, query: $duplicates)->statusCode)->toBe(200);
    foreach (['fieldId', 'hyperFieldId', 'siteId', 'elementId', 'inputContext'] as $key) {
        expect(fn() => CpActionRequest::run('input-settings-save', $f['users']['editor'], $body, query: [$key => ['invalid']]))->toThrow(ForbiddenHttpException::class);
    }

    $cases = [
        [$f['users']['editor'], array_replace($body, ['inputContext' => 'tampered'])],
        [$f['users']['editor'], signedCpBody($f, context: ['expires' => time() - 1])],
        [$f['users']['otherEditor'], $body],
        [$f['users']['noSite'], signedCpBody($f, 'noSite')],
        [$f['users']['noOwner'], signedCpBody($f, 'noOwner')],
        [$f['users']['editor'], array_replace($body, ['elementId' => $f['otherOwner']->id])],
        [$f['users']['editor'], array_replace(signedCpBody($f, context: ['ownerId' => $f['otherOwner']->id]), ['elementId' => null])],
        [$f['users']['editor'], array_replace($body, ['siteId' => $f['otherSite']->id])],
    ];
    foreach ($cases as [$user, $payload]) {
        // No response is returned when access is denied, so posted/private content cannot leak.
        expect(fn() => CpActionRequest::run('input-settings-save', $user, $payload))->toThrow(ForbiddenHttpException::class);
    }
    expect(fn() => CpActionRequest::run('input-settings-save', $f['users']['editor'], $body, false))->toThrow(\yii\web\BadRequestHttpException::class);
});

it('requires a valid owner context on every public input endpoint', function(string $action) {
    $f = $this->cpFixture;
    $body = signedCpBody($f, 'noOwner') + ['hyperFieldId' => $f['field']->id];
    expect(fn() => CpActionRequest::run($action, $f['users']['noOwner'], $body))
        ->toThrow(ForbiddenHttpException::class, 'User is not authorized to edit this element.');
})->with(['create-links', 'bulk-element-select', 'input-settings', 'input-settings-save', 'preview-embed', 'create-matrix-entry', 'matrix-field-layout']);

it('rejects inaccessible main and custom-field selections while rendering permitted clipboard content', function() {
    $f = $this->cpFixture;
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new \craft\fieldlayoutelements\CustomField($related)]);
    $url->setFieldLayout($layout);
    $f['field']->setLinkTypes([F::linkTypeConfig($url), F::linkTypeConfig(\verbb\hyper\links\Entry::class)]);
    expect(Craft::$app->fields->saveField($f['field']))->toBeTrue();
    $body = signedCpBody($f);
    $user = $f['users']['editor'];
    expect($f['owner']->canView($user))->toBeTrue();
    expect($f['otherOwner']->canView($user))->toBeFalse();
    try {
        foreach (['entry', 'url'] as $type) {
            $seed = fn($id) => $type === 'entry'
                ? ['linkValue' => [$id]]
                : ['linkValue' => 'https://example.test/clipboard', 'fields' => [$related->handle => [$id]]];
            $allowed = CpActionRequest::run('create-links', $user, array_replace($body, [
                'handle' => $type, 'mode' => 'seed', 'seeds' => [$seed($f['owner']->id)],
            ]));
            expect($allowed->statusCode)->toBe(200);
            expect($allowed->data['blocks'])->toHaveCount(1);
            expect(fn() => CpActionRequest::run('create-links', $user, array_replace($body, [
                'handle' => $type, 'mode' => 'seed', 'seeds' => [$seed($f['otherOwner']->id)],
            ])))->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');

            expect(fn() => CpActionRequest::run('input-settings', $user, array_replace($body, [
                'data' => ['handle' => $type] + $seed($f['otherOwner']->id),
            ])))->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');
        }
    } finally {
        Craft::$app->fields->deleteField($related);
    }
});

it('rejects inaccessible selections before saving an owner while permitting visible targets', function() {
    $f = $this->cpFixture;
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new \craft\fieldlayoutelements\CustomField($related)]);
    $url->setFieldLayout($layout);
    $f['field']->setLinkTypes([F::linkTypeConfig($url), F::linkTypeConfig(\verbb\hyper\links\Entry::class)]);
    expect(Craft::$app->fields->saveField($f['field']))->toBeTrue();

    $owner = Entry::find()->id($f['owner']->id)->status(null)->one();
    $user = $f['users']['editor'];

    try {
        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'entry',
            'linkValue' => [$f['otherOwner']->id],
        ]]);
        expect(fn() => CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($owner, false)))
            ->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');

        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'url',
            'linkValue' => 'https://example.test/nested',
            'fields' => [$related->handle => [$f['otherOwner']->id]],
        ]]);
        expect(fn() => CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($owner, false)))
            ->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');

        $f['otherOwner']->enabled = false;
        expect(Craft::$app->elements->saveElement($f['otherOwner']))->toBeTrue();
        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'url',
            'linkValue' => 'https://example.test/disabled-nested',
            'fields' => [$related->handle => [$f['otherOwner']->id]],
        ]]);
        expect(fn() => CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($owner, false)))
            ->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');

        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'entry',
            'linkValue' => [$f['owner']->id],
        ]]);
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($owner, false)))->toBeTrue();
    } finally {
        Craft::$app->fields->deleteField($related);
    }
});
