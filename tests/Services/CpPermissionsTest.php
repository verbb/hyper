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
