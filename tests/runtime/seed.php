<?php

// Integration fixtures use real plugin lifecycles and project-config handlers.
require_once __DIR__ . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$config = json_decode(file_get_contents(__DIR__ . '/suite.json'), true, 512, JSON_THROW_ON_ERROR);
if (getenv('DDEV_SITENAME') !== $config['project']) {
    throw new RuntimeException('Integration setup requires the isolated Hyper test project.');
}
foreach (['vizy', 'neo'] as $handle) {
    if (!$app->getPlugins()->isPluginInstalled($handle)) {
        $app->getPlugins()->installPlugin($handle);
    }
}
$app->getProjectConfig()->saveModifiedConfigData();
