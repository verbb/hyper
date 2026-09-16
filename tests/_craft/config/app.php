<?php

use craft\helpers\App;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'CraftCMS-HyperTests',
    'components' => [
        'assetManager' => function() {
            return Craft::createObject(App::assetManagerConfig());
        },
        'projectConfig' => function() {
            $config = craft\helpers\App::projectConfigConfig();
            $config['writeYamlAutomatically'] = false;

            return Craft::createObject($config);
        },
    ],
];
