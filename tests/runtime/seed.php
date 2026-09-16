<?php

// Vizy's integration test uses the installed plugin lifecycle and project-config handlers.
require_once __DIR__ . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
if (getenv('DDEV_SITENAME') !== 'hyper-react-tests') {
    throw new RuntimeException('Vizy test setup requires the isolated Hyper test project.');
}
if (!$app->getPlugins()->isPluginInstalled('vizy')) {
    $app->getPlugins()->installPlugin('vizy');
}
$app->getProjectConfig()->saveModifiedConfigData();
