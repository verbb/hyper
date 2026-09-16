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
