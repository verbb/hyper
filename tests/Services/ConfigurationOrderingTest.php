<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Email;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkTypeConfig;

it('retains and stably orders every configured link type', function(string $scope, array $positions, array $expected) {
    $types = [F::linkTypeConfig(Url::class) + ['sortOrder' => $positions[0]], F::linkTypeConfig(Email::class) + ['sortOrder' => $positions[1]]];
    if ($scope === 'field') {
        $field = F::hyperFieldWithLinkTypes($types);
        expect(array_map(fn($type) => $type->handle, $field->getLinkTypes()))->toBe($expected);
    } else {
        $service = Hyper::$plugin->linkTypeConfigs;
        $config = new LinkTypeConfig(['name' => 'Ordering', 'handle' => F::handle('ordering'), 'linkTypes' => $types]);
        try {
            expect($service->saveConfig($config))->toBeTrue();
            expect(array_column($service->getConfigByUid($config->uid)->linkTypes, 'handle'))->toBe($expected);
            // Applied Project Config can also contain the configurator's ordering metadata.
            Craft::$app->projectConfig->set(\verbb\hyper\services\LinkTypeConfigs::PROJECT_CONFIG_PATH . '.' . $config->uid . '.linkTypes', \craft\helpers\ProjectConfig::packAssociativeArrays($types));
            expect(array_map(fn($type) => $type->handle, $service->getLinkTypes($config->uid)))->toBe($expected);
        } finally {
            $service->deleteConfig($config->uid);
        }
    }
})->with([
    ['field', ['0', '0'], ['url', 'email']],
    ['shared', ['0', '0'], ['url', 'email']],
    ['field', ['3', '1'], ['email', 'url']],
    ['shared', ['3', '1'], ['email', 'url']],
]);
