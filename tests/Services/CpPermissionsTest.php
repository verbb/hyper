<?php

use craft\elements\User;
use craft\helpers\Json;
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
