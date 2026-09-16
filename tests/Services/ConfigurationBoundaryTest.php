<?php

use craft\elements\User;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\controllers\PluginController;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkTypeConfig;
use verbb\hyper\services\LinkTypeConfigs;
use yii\web\ForbiddenHttpException;

it('restricts the link type configuration editor to administrators', function() {
    $name = F::handle('hyperConfigEditor');
    $editor = new User(['username' => $name, 'email' => $name . '@example.test', 'pending' => false]);
    expect(Craft::$app->elements->saveElement($editor))->toBeTrue();
    Craft::$app->userPermissions->saveUserPermissions($editor->id, ['accessCp']);
    $editor = User::find()->id($editor->id)->status(null)->one();
    $config = Hyper::$plugin->linkTypeConfigs->getDefaultConfig();
    try {
        expect(fn() => CpActionRequest::run('edit-link-type-config', $editor, ['uid' => $config->uid], controllerClass: PluginController::class))
            ->toThrow(ForbiddenHttpException::class);
        $response = CpActionRequest::run('edit-link-type-config', User::find()->admin()->one(), ['uid' => $config->uid], controllerClass: PluginController::class);
        expect($response->statusCode)->toBe(200);
    } finally {
        Craft::$app->elements->deleteElement($editor, true);
    }
});

it('rejects a field configuration with no enabled link type', function() {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $field->setLinkTypes([F::linkTypeConfig(Url::class, false)]);
    expect(Craft::$app->fields->saveField($field))->toBeFalse();
    expect($field->getErrors('linkTypes'))->not->toBeEmpty();
    Craft::$app->fields->refreshFields();
    expect(Craft::$app->fields->getFieldById($field->id)->getLinkTypes()[0]->enabled)->toBeTrue();
});

it('rejects invalid shared link types without replacing the saved configuration', function(string $invalid) {
    $service = Hyper::$plugin->linkTypeConfigs;
    $config = new LinkTypeConfig(['name' => 'Validated shared config', 'handle' => F::handle('hyperConfig'), 'linkTypes' => [F::linkTypeConfig(Url::class)]]);
    expect($service->saveConfig($config))->toBeTrue();
    $path = LinkTypeConfigs::PROJECT_CONFIG_PATH . '.' . $config->uid;
    $original = Craft::$app->projectConfig->get($path);
    try {
        if ($invalid === 'duplicate') {
            $config->linkTypes[] = F::linkTypeConfig(Url::class);
        } elseif ($invalid === 'disabled') {
            $config->linkTypes[0]['enabled'] = false;
        } elseif ($invalid === 'missing label') {
            $config->linkTypes[0]['label'] = '';
        } elseif ($invalid === 'colliding handles') {
            $config->linkTypes = array_map(fn($handle) => F::linkTypeConfig(new Url(['handle' => $handle, 'isCustom' => true])), ['promo-link', 'promoLink']);
        } else {
            $config->linkTypes = [F::linkTypeConfig(new \verbb\hyper\links\Site(['sites' => []]))];
        }
        expect($service->saveConfig($config))->toBeFalse();
        expect($config->getErrors())->not->toBeEmpty();
        expect(Craft::$app->projectConfig->get($path))->toBe($original);
    } finally {
        $service->deleteConfig($config->uid);
    }
})->with(['duplicate', 'disabled', 'missing label', 'missing sites', 'colliding handles']);

it('validates type-specific settings when saving a field-owned configuration', function() {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $field->setLinkTypes([F::linkTypeConfig(new \verbb\hyper\links\Site(['sites' => []]))]);
    expect(Craft::$app->fields->saveField($field))->toBeFalse();
    expect($field->getLinkTypes()[0]->getErrors('sites'))->not->toBeEmpty();
});

it('rejects distinct handles that would share a GraphQL type', function() {
    $field = F::hyperField(['linkTypes' => [Url::class]]);
    $field->setLinkTypes(array_map(fn($handle) => F::linkTypeConfig(new Url(['handle' => $handle, 'isCustom' => true])), ['promo-link', 'promoLink']));
    $first = $field->getLinkTypeByHandle('promo-link');
    $second = $field->getLinkTypeByHandle('promoLink');
    expect($first->getGqlTypeName())->toBe($second->getGqlTypeName());
    expect(Craft::$app->fields->saveField($field))->toBeFalse();
    expect($field->getErrors('linkTypes'))->not->toBeEmpty();
});
