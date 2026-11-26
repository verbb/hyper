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
            'linkValue' => 'https://example.test/guest-upload',
            'fields' => [$assetField->handle => [$own->id]],
        ]]);
        expect(fn() => CpActionRequest::asUser(null, fn() => Craft::$app->elements->saveElement($owner, false)))
            ->toThrow(ForbiddenHttpException::class);
        expect(Asset::find()->id($own->id)->one()->volumeId)->toBeNull();

        // The web installer saves project config elements before any user exists.
        $owner = Entry::find()->id($f['owner']->id)->status(null)->one();
        $owner->setFieldValue($f['field']->handle, [[
            'handle' => 'url',
            'linkValue' => 'https://example.test/guest-no-upload',
        ]]);
        expect(CpActionRequest::asUser(null, fn() => Craft::$app->elements->saveElement($owner, false)))->toBeTrue();

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
})->with(['create-links', 'bulk-element-select', 'input-settings', 'input-settings-save', 'preview-embed', 'link-field-layout', 'create-matrix-entry', 'matrix-field-layout']);

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

it('keeps stored selections the editor cannot view while rejecting new ones', function(bool $custom) {
    $f = $this->cpFixture;
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($related)]);
    $url->setFieldLayout($layout);
    $field = $f['field'];
    $field->setLinkTypes([F::linkTypeConfig($url), F::linkTypeConfig(\verbb\hyper\links\Entry::class)]);
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $user = $f['users']['editor'];
    $admin = User::find()->admin()->one();
    // The private section exists on every site, so the same target can be re-posted for another site.
    $privateSection = F::entrySection(allSites: true);
    $private = F::plainEntry($privateSection, 'Private target');
    $otherPrivate = F::plainEntry($privateSection, 'Other private target');
    $payload = fn(Entry $target, ?int $siteId = null) => $custom
        ? ['handle' => 'url', 'linkValue' => 'https://example.test/stored', 'fields' => [$related->handle => [$target->id]]]
        : ['handle' => 'entry', 'linkValue' => [$target->id], 'linkSiteId' => $siteId ?? $f['site']->id];
    $load = fn(int $id) => Entry::find()->id($id)->siteId($f['site']->id)->drafts(null)->provisionalDrafts(null)->status(null)->one();
    $save = fn($element) => CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($element, false));
    $denied = 'User is not authorized to view a selected element.';

    try {
        // Someone with broader access links an entry the editor can't view.
        $owner = $load($f['owner']->id);
        $owner->setFieldValue($field->handle, [$payload($private)]);
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->canView($private)))->toBeFalse();

        // The field input re-posts the stored link alongside an unrelated edit.
        $owner = $load($f['owner']->id);
        $owner->slug = 'restricted-edit';
        $owner->setFieldValue($field->handle, [$payload($private)]);
        expect($save($owner))->toBeTrue();
        expect($load($f['owner']->id)->slug)->toBe('restricted-edit');

        // Autosave creates a provisional draft from the canonical entry, then saves the posted values to it.
        $draft = CpActionRequest::asUser($user, fn() => Craft::$app->drafts->createDraft($load($f['owner']->id), $user->id, provisional: true));
        $draft->slug = 'provisional-edit';
        $draft->setFieldValue($field->handle, [$payload($private)]);
        expect($save($draft))->toBeTrue();

        $copy = CpActionRequest::asUser($user, fn() => Craft::$app->elements->duplicateElement($load($f['owner']->id)));
        expect($load($copy->id)->getFieldValue($field->handle)->getLinks())->toHaveCount(1);

        // Swapping in, or re-siting, an unviewable target is still a new selection.
        $draft = $load($draft->id);
        $draft->setFieldValue($field->handle, [$payload($otherPrivate)]);
        expect(fn() => $save($draft))->toThrow(ForbiddenHttpException::class, $denied);

        $owner = $load($f['owner']->id);
        $owner->setFieldValue($field->handle, [$payload($otherPrivate)]);
        expect(fn() => $save($owner))->toThrow(ForbiddenHttpException::class, $denied);

        if (!$custom) {
            $owner = $load($f['owner']->id);
            $owner->setFieldValue($field->handle, [$payload($private, $f['otherSite']->id)]);
            expect(fn() => $save($owner))->toThrow(ForbiddenHttpException::class, $denied);
        }

        $draft = $load($draft->id);
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->drafts->applyDraft($draft)))->toBeInstanceOf(Entry::class);
        expect($load($f['owner']->id)->slug)->toBe('provisional-edit');

        // Another entry gains nothing from stored selections elsewhere, either as a draft or a new entry.
        $plain = F::plainEntry($f['owner']->getSection(), 'Plain owner');
        $draft = CpActionRequest::asUser($user, fn() => Craft::$app->drafts->createDraft($plain, $user->id, provisional: true));
        $draft->setFieldValue($field->handle, [$payload($private)]);
        expect(fn() => $save($draft))->toThrow(ForbiddenHttpException::class, $denied);

        $new = new Entry([
            'sectionId' => $plain->sectionId,
            'typeId' => $plain->typeId,
            'siteId' => $f['site']->id,
            'title' => 'New restricted entry',
        ]);
        $new->setFieldValue($field->handle, [$payload($private)]);
        expect(fn() => $save($new))->toThrow(ForbiddenHttpException::class, $denied);

        // A draft someone else saved can be applied, since its selections are already stored.
        $draft = Craft::$app->drafts->createDraft($plain, $admin->id, 'Admin draft');
        $draft->setFieldValue($field->handle, [$payload($private)]);
        expect(Craft::$app->elements->saveElement($draft))->toBeTrue();
        $draft = $load($draft->id);
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->drafts->applyDraft($draft)))->toBeInstanceOf(Entry::class);
        expect($load($plain->id)->getFieldValue($field->handle)->getLinks())->toHaveCount(1);
    } finally {
        Craft::$app->fields->deleteField($related);
    }
})->with([false, true]);

it('keeps stored selections in nested Matrix entries while rejecting new ones', function() {
    $f = $this->cpFixture;
    $fixture = F::matrixFieldWithHyper();
    $section = F::entrySectionWithField($fixture['matrix']);
    $private = F::plainEntry(F::entrySection(), 'Private target');
    $otherPrivate = F::plainEntry(F::entrySection(), 'Other private target');
    $owner = F::entryWithMatrixHyperLink(
        $section, $fixture['matrix'], $fixture['hyperField'], $fixture['blockEntryType'], [F::entryLinkPayload($private)],
    );
    $user = $f['users']['editor'];
    $handle = $fixture['hyperField']->handle;
    $load = fn(int $id) => Entry::find()->id($id)->drafts(null)->provisionalDrafts(null)->status(null)->one();
    $save = fn($element) => CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($element, false));
    $denied = 'User is not authorized to view a selected element.';
    expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->canView($private)))->toBeFalse();

    $draft = CpActionRequest::asUser($user, fn() => Craft::$app->drafts->createDraft($load($owner->id), $user->id, provisional: true));
    $draft->title = 'Provisional edit';
    expect($save($draft))->toBeTrue();

    $child = $load($owner->getFieldValue($fixture['matrix']->handle)->one()->id);
    $child->title = 'Restricted edit';
    $child->setFieldValue($handle, [F::entryLinkPayload($private)]);
    expect($save($child))->toBeTrue();

    $child = $load($child->id);
    $child->setFieldValue($handle, [F::entryLinkPayload($otherPrivate)]);
    expect(fn() => $save($child))->toThrow(ForbiddenHttpException::class, $denied);

    $draft = $load($draft->id);
    expect(CpActionRequest::asUser($user, fn() => Craft::$app->drafts->applyDraft($draft)))->toBeInstanceOf(Entry::class);
    $child = $load($owner->id)->getFieldValue($fixture['matrix']->handle)->one();
    expect($child->getFieldValue($handle)->getLinks()[0]->getElement(null)?->id)->toBe($private->id);
});

function cpStoredSelectionFixture(array $f): array
{
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($related)]);
    $url->setFieldLayout($layout);
    $field = $f['field'];
    $field->setLinkTypes([F::linkTypeConfig($url), F::linkTypeConfig(\verbb\hyper\links\Entry::class)]);
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $privateSection = F::entrySection();

    return [
        'related' => $related,
        'field' => $field,
        'private' => F::plainEntry($privateSection, 'Private target'),
        'otherPrivate' => F::plainEntry($privateSection, 'Other private target'),
        'payload' => fn(bool $custom, Entry $target) => $custom
            ? ['handle' => 'url', 'linkValue' => 'https://example.test/stored', 'fields' => [$related->handle => [$target->id]]]
            : ['handle' => 'entry', 'linkValue' => [$target->id], 'linkSiteId' => $f['site']->id],
        'load' => fn(int $id) => Entry::find()->id($id)->siteId($f['site']->id)->drafts(null)->provisionalDrafts(null)->revisions(null)->status(null)->one(),
    ];
}

it('keeps stored selections when refreshing a link layout while rejecting new ones', function(bool $custom) {
    $f = $this->cpFixture;
    $s = cpStoredSelectionFixture($f);
    $user = $f['users']['editor'];
    $body = signedCpBody($f) + ['namespace' => 'fields[hyperData][link]'];
    $refresh = fn(Entry $target) => CpActionRequest::run('link-field-layout', $user, array_replace($body, ['data' => ($s['payload'])($custom, $target)]));

    try {
        $owner = ($s['load'])($f['owner']->id);
        $owner->setFieldValue($s['field']->handle, [($s['payload'])($custom, $s['private'])]);
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->canView($s['private'])))->toBeFalse();

        // Changing an existing link re-renders its conditional fields with the stored selections.
        expect($refresh($s['private'])->statusCode)->toBe(200);
        expect($refresh($f['owner'])->statusCode)->toBe(200);
        expect(fn() => $refresh($s['otherPrivate']))->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');
    } finally {
        Craft::$app->fields->deleteField($s['related']);
    }
})->with([false, true]);

it('keeps stored selections when refreshing a link layout for a field with a layout handle override', function() {
    $f = $this->cpFixture;
    $s = cpStoredSelectionFixture($f);
    $field = $s['field'];
    $user = $f['users']['editor'];
    $body = signedCpBody($f) + ['namespace' => 'fields[hyperData][link]'];
    $refresh = fn(Entry $target) => CpActionRequest::run('link-field-layout', $user, array_replace($body, ['data' => ($s['payload'])(false, $target)]));

    try {
        // Replace the field's layout element with one that overrides its handle.
        $entryType = Craft::$app->entries->getEntryTypeById($f['owner']->typeId);
        $layout = $entryType->getFieldLayout();
        $tab = $layout->getTabs()[0];
        $elements = array_filter($tab->getElements(), fn($element) => !$element instanceof CustomField || $element->getField()->id !== $field->id);
        $tab->setElements([...$elements, new CustomField($field, ['handle' => 'hyperOverride'])]);
        $entryType->setFieldLayout($layout);
        expect(Craft::$app->entries->saveEntryType($entryType))->toBeTrue();

        $owner = ($s['load'])($f['owner']->id);
        $owner->setFieldValue('hyperOverride', [($s['payload'])(false, $s['private'])]);
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();

        expect($refresh($s['private'])->statusCode)->toBe(200);
        expect($refresh($f['owner'])->statusCode)->toBe(200);
        expect(fn() => $refresh($s['otherPrivate']))->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');
    } finally {
        Craft::$app->fields->deleteField($s['related']);
    }
});

it('lets a view-only user draft an entry with stored selections they cannot view', function(bool $custom) {
    $f = $this->cpFixture;
    $s = cpStoredSelectionFixture($f);
    $field = $s['field'];
    $load = $s['load'];
    $user = $f['users']['editor'];

    try {
        // Craft lets anyone who can view an entry create a draft of it.
        $viewOnly = F::entrySection($field);
        Craft::$app->set('userPermissions', new \craft\services\UserPermissions());
        $permissions = Craft::$app->userPermissions->getPermissionsByUserId($user->id);
        Craft::$app->userPermissions->saveUserPermissions($user->id, [...$permissions, 'viewEntries:' . $viewOnly->uid, 'viewPeerEntries:' . $viewOnly->uid]);
        $user = User::find()->id($user->id)->status(null)->one();
        $entry = $load(F::plainEntry($viewOnly, 'View-only owner')->id);
        $entry->setFieldValue($field->handle, [($s['payload'])($custom, $s['private'])]);
        expect(Craft::$app->elements->saveElement($entry))->toBeTrue();
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->canSave($load($entry->id))))->toBeFalse();
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->canView($s['private'])))->toBeFalse();

        $draft = CpActionRequest::asUser($user, fn() => Craft::$app->drafts->createDraft($load($entry->id), $user->id, 'Viewer draft'));
        expect($draft)->toBeInstanceOf(Entry::class);

        $draft = $load($draft->id);
        $draft->slug = 'viewer-draft-edit';
        $draft->setFieldValue($field->handle, [($s['payload'])($custom, $s['private'])]);
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($draft, false)))->toBeTrue();

        $draft = $load($draft->id);
        $draft->setFieldValue($field->handle, [($s['payload'])($custom, $s['otherPrivate'])]);
        expect(fn() => CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($draft, false)))
            ->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');
    } finally {
        Craft::$app->fields->deleteField($s['related']);
    }
})->with([false, true]);

it('lets an editor revert to a revision with stored selections they cannot view', function(bool $custom) {
    $f = $this->cpFixture;
    $s = cpStoredSelectionFixture($f);
    $field = $s['field'];
    $load = $s['load'];
    $user = $f['users']['editor'];
    $admin = User::find()->admin()->one();

    try {
        $owner = $load($f['owner']->id);
        $owner->setFieldValue($field->handle, [($s['payload'])($custom, $s['private'])]);
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        $revisionId = Craft::$app->revisions->createRevision($load($f['owner']->id), $admin->id, 'With private', force: true);

        // The canonical entry later drops the hidden target, so only the revision holds it.
        $owner = $load($f['owner']->id);
        $owner->setFieldValue($field->handle, []);
        expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
        expect($load($f['owner']->id)->getFieldValue($field->handle)->getLinks())->toHaveCount(0);

        $revision = Entry::find()->id($revisionId)->revisions(true)->siteId($f['site']->id)->status(null)->one();
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->revisions->revertToRevision($revision, $user->id)))->toBeInstanceOf(Entry::class);
        expect($load($f['owner']->id)->getFieldValue($field->handle)->getLinks())->toHaveCount(1);
    } finally {
        Craft::$app->fields->deleteField($s['related']);
    }
})->with([false, true]);

it('propagates permitted selections without requiring access to sibling sites', function(bool $draft, bool $custom) {
    $f = $this->cpFixture;
    $related = F::entriesField();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($related)]);
    $url->setFieldLayout($layout);
    $field = $f['field'];
    $field->multipleLinks = true;
    $field->syncLinksAcrossSites = true;
    $field->translationMethod = \craft\base\Field::TRANSLATION_METHOD_SITE;
    $field->setLinkTypes([F::linkTypeConfig($url), F::linkTypeConfig(\verbb\hyper\links\Entry::class)]);
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $user = $f['users']['editor'];
    $owner = Entry::find()->id($f['owner']->id)->siteId($f['site']->id)->one();
    if ($draft) {
        $owner = Craft::$app->drafts->createDraft($owner, $user->id, 'Restricted translator');
    }
    $payload = $custom
        ? ['handle' => 'url', 'linkValue' => '/translated', 'fields' => [$related->handle => [$f['owner']->id]]]
        : ['handle' => 'entry', 'linkValue' => [$f['owner']->id], 'linkSiteId' => $f['site']->id];
    try {
        $owner->setFieldValue($field->handle, [$payload]);
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($owner, false)))->toBeTrue();
        $sibling = Entry::find()->id($owner->id)->siteId($f['otherSite']->id)->drafts(null)->status(null)->one();
        $links = $sibling->getFieldValue($field->handle)->getLinks();
        expect($links)->toHaveCount(1);
        $selected = $custom ? $links[0]->getFieldValue($related->handle)->one() : $links[0]->getElement();
        expect($selected->siteId)->toBe($f['otherSite']->id);
        expect(CpActionRequest::asUser($user, fn() => Craft::$app->elements->canView($selected)))->toBeFalse();
        if ($draft) {
            $published = Entry::find()->id($f['owner']->id)->siteId($f['otherSite']->id)->one();
            expect($published->getFieldValue($field->handle)->getLinks())->toBe([]);
        }

        // The internal propagation exemption must not survive into another authored save.
        $owner->setFieldValue($field->handle, [[
            'handle' => 'entry', 'linkValue' => [$f['owner']->id], 'linkSiteId' => $f['otherSite']->id,
        ]]);
        expect(fn() => CpActionRequest::asUser($user, fn() => Craft::$app->elements->saveElement($owner, false)))
            ->toThrow(ForbiddenHttpException::class, 'User is not authorized to view a selected element.');
    } finally {
        Craft::$app->fields->deleteField($related);
    }
})->with([false, true])->with([false, true]);

it('refreshes conditional link fields and tabs without replacing unchanged inputs', function() {
    $f = $this->cpFixture;
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $condition = Url::createCondition();
    $condition->setConditionRules([$condition->createConditionRule([
        'class' => \verbb\hyper\elements\conditions\LinkValueConditionRule::class,
        'operator' => '**', 'value' => 'details',
    ])]);
    $text = $layout->getField('linkText');
    $text->setElementCondition($condition);
    $extra = new \craft\models\FieldLayoutTab(['name' => 'Conditional', 'layout' => $layout, 'uid' => StringHelper::UUID()]);
    $extra->setElementCondition(clone $condition);
    $extra->setElements([new \craft\fieldlayoutelements\Tip(['tip' => 'Conditional content', 'uid' => StringHelper::UUID()])]);
    $layout->setTabs([...$layout->getTabs(), $extra]);
    $url->setFieldLayout($layout);
    $f['field']->setLinkTypes([F::linkTypeConfig($url)]);
    expect(Craft::$app->fields->saveField($f['field']))->toBeTrue();
    $layout = $f['field']->getLinkTypeByHandle('url')->getFieldLayout();
    $textUid = $layout->getField('linkText')->uid;
    $body = signedCpBody($f) + ['namespace' => 'fields[hyperData][test]'];
    $hidden = CpActionRequest::run('link-field-layout', $f['users']['editor'], $body)->data;
    expect(array_column($hidden['tabs'], 'label'))->not->toContain('Conditional');
    $elements = array_merge(...array_column($hidden['tabs'], 'elements'));
    expect(array_column($elements, 'html', 'uid')[$textUid])->toBeFalse();
    $visible = [];
    foreach ($hidden['tabs'] as $tab) {
        $visible[$tab['uid']] = array_column(array_filter($tab['elements'], fn($e) => is_string($e['html'])), 'uid');
    }
    $body['visibleElements'] = $visible;
    $body['data']['linkValue'] = '/details';
    $body['data']['linkText'] = 'Retained label';
    $shown = CpActionRequest::run('link-field-layout', $f['users']['editor'], $body)->data;
    expect(array_column($shown['tabs'], 'label'))->toContain('Conditional');
    $elements = array_column(array_merge(...array_column($shown['tabs'], 'elements')), 'html', 'uid');
    expect($elements[$textUid])->toContain('Retained label')->toContain('fields[hyperData][test][linkText]');
    expect($elements[$layout->getField('linkValue')->uid])->toBeTrue();
});

it('refreshes conditional fields and creates Matrix entries for saved links of a disabled type', function() {
    $f = $this->cpFixture;
    $blockType = new \craft\models\EntryType(['name' => 'Disabled type block', 'handle' => F::handle('disabledTypeBlock'), 'hasTitleField' => true]);
    expect(Craft::$app->entries->saveEntryType($blockType))->toBeTrue();
    $matrix = new \craft\fields\Matrix(['name' => 'Disabled type Matrix', 'handle' => F::handle('disabledTypeMatrix')]);
    $matrix->setEntryTypes([$blockType]);
    expect(Craft::$app->fields->saveField($matrix))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $condition = Url::createCondition();
    $condition->setConditionRules([$condition->createConditionRule([
        'class' => \verbb\hyper\elements\conditions\LinkValueConditionRule::class,
        'operator' => '**', 'value' => 'details',
    ])]);
    $layout->getField('linkText')->setElementCondition($condition);
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($matrix, ['uid' => StringHelper::UUID()])]);
    $url->setFieldLayout($layout);
    $f['field']->setLinkTypes([F::linkTypeConfig(\verbb\hyper\links\Entry::class), F::linkTypeConfig($url, false)]);
    expect(Craft::$app->fields->saveField($f['field']))->toBeTrue();

    $body = signedCpBody($f) + ['namespace' => 'fields[hyperData][test]'];
    $body['data']['linkValue'] = '/details';
    $body['data']['linkText'] = 'Shown label';
    $response = CpActionRequest::run('link-field-layout', $f['users']['editor'], $body);
    expect(Json::encode($response->data['tabs']))->toContain('Shown label');

    $matrixBody = signedCpBody($f) + ['hyperFieldId' => $f['field']->id, 'entryTypeId' => $blockType->id, 'namespace' => 'fields[hyperData][test]'];
    $matrixBody['fieldId'] = $matrix->id;
    expect(CpActionRequest::run('create-matrix-entry', $f['users']['editor'], $matrixBody)->data['blockHtml'])->toBeString();

    // Unknown types still resolve to nothing.
    $body['data']['handle'] = 'unknown';
    expect(fn() => CpActionRequest::run('link-field-layout', $f['users']['editor'], $body))
        ->toThrow(\yii\web\NotFoundHttpException::class);
});

it('keeps user-hidden fields out of conditional refresh responses', function() {
    $f = $this->cpFixture;
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $condition = User::createCondition();
    $condition->setConditionRules([$condition->createConditionRule([
        'class' => \craft\elements\conditions\users\AdminConditionRule::class, 'value' => true,
    ])]);
    $layout->getField('linkText')->setUserCondition($condition);
    $url->setFieldLayout($layout);
    $f['field']->setLinkTypes([F::linkTypeConfig($url)]);
    expect(Craft::$app->fields->saveField($f['field']))->toBeTrue();
    $body = signedCpBody($f) + ['namespace' => 'fields[hyperData][test]'];
    $body['data']['linkText'] = 'Hidden label';
    $response = CpActionRequest::run('link-field-layout', $f['users']['editor'], $body);
    expect(Json::encode($response->data['tabs']))->not->toContain('Hidden label');
});

it('limits single and bulk entry pickers to sections the current user can view', function(bool $bulk, bool $reader) {
    $f = $this->cpFixture;
    $user = $f['users']['editor'];
    if ($reader) {
        $permissions = Craft::$app->userPermissions->getPermissionsByUserId($user->id);
        Craft::$app->userPermissions->saveUserPermissions($user->id, array_values(array_filter($permissions, fn($permission) => !str_starts_with($permission, 'save'))));
        $user = User::find()->id($user->id)->one();
    }
    $link = new \verbb\hyper\links\Entry(['allowElementsWithoutUri' => true]);
    $field = $f['field'];
    $field->setLinkTypes([F::linkTypeConfig($link)]);
    $script = CpActionRequest::asUser($user, function() use ($bulk, $link, $field) {
        $view = Craft::$app->getView();
        $view->startJsBuffer();
        try {
            if ($bulk) {
                $html = $field->getBulkElementSelectHtml($link->handle);
            } else {
                $html = $link->getInputHtml(new \verbb\hyper\fieldlayoutelements\LinkField(), $field);
            }
        } finally {
            $script = $view->clearJsBuffer();
        }
        return $html . $script;
    });
    preg_match('/new Craft\\.BaseElementSelectInput\\((?:[^,{]+,\s*)?(\{[^\n]+\})\);/', $script, $matches);
    expect($matches)->toHaveCount(2);
    $settings = Json::decode($matches[1]);
    // Exercise Craft's actual modal action with Hyper's rendered settings, not a hand-built context.
    $response = CpActionRequest::run('body', $user, [
        'elementType' => Entry::class,
        'context' => $settings['modalSettings']['context'] ?? $settings['context'] ?? 'field',
        'sources' => $settings['sources'],
        'siteId' => $f['site']->id,
    ], controllerClass: \craft\controllers\ElementSelectorModalsController::class);
    expect($response->data['html'])
        ->toContain('section:' . $f['owner']->getSection()->uid)
        ->not->toContain('section:' . $f['otherOwner']->getSection()->uid);
    $ids = CpActionRequest::asUser($user, fn() => Craft::configure(Entry::find(), $settings['criteria'])
        ->siteId($f['site']->id)
        ->id([$f['owner']->id, $f['otherOwner']->id])
        ->ids());
    expect(array_map('intval', $ids))->toBe([(int)$f['owner']->id]);
})->with([false, true])->with([false, true]);

it('limits single and bulk asset pickers to viewable volumes', function(bool $bulk) {
    $f = $this->cpFixture;
    $handle = F::handle('pickerFs');
    $fs = new \craft\fs\Local(['name' => $handle, 'handle' => $handle, 'path' => sys_get_temp_dir() . '/' . $handle]);
    expect(Craft::$app->fs->saveFilesystem($fs))->toBeTrue();
    $volumes = [];
    foreach (['allowed', 'denied'] as $role) {
        $handle = F::handle('pickerVolume');
        $volume = new \craft\models\Volume(['name' => $handle, 'handle' => $handle, 'fsHandle' => $fs->handle, 'subpath' => $role]);
        expect(Craft::$app->volumes->saveVolume($volume))->toBeTrue();
        $volumes[$role] = $volume;
    }
    $user = $f['users']['editor'];
    Craft::$app->set('userPermissions', new \craft\services\UserPermissions());
    $permissions = Craft::$app->userPermissions->getPermissionsByUserId($user->id);
    expect(Craft::$app->userPermissions->saveUserPermissions($user->id, [...$permissions, 'viewAssets:' . $volumes['allowed']->uid]))->toBeTrue();
    $user = User::find()->id($user->id)->one();
    $link = new \verbb\hyper\links\Asset();
    $field = $f['field'];
    $field->setLinkTypes([F::linkTypeConfig($link)]);
    $script = CpActionRequest::asUser($user, function() use ($bulk, $link, $field) {
        $view = Craft::$app->getView();
        $view->startJsBuffer();
        try {
            $html = $bulk ? $field->getBulkElementSelectHtml($link->handle) : $link->getInputHtml(new \verbb\hyper\fieldlayoutelements\LinkField(), $field);
        } finally {
            $script = $view->clearJsBuffer();
        }
        return $html . $script;
    });
    preg_match('/new Craft\\.BaseElementSelectInput\\((?:[^,{]+,\s*)?(\{[^\n]+\})\);/', $script, $matches);
    expect($matches)->toHaveCount(2);
    $settings = Json::decode($matches[1]);
    expect($settings['criteria']['editable'])->toBeTrue();
    $response = CpActionRequest::run('body', $user, [
        'elementType' => Asset::class,
        'context' => $settings['context'] ?? 'field',
        'sources' => $settings['sources'],
    ], controllerClass: \craft\controllers\ElementSelectorModalsController::class);
    expect($response->data['html'])->toContain($volumes['allowed']->name)->not->toContain($volumes['denied']->name);
})->with([false, true]);
