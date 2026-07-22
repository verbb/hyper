<?php

declare(strict_types=1);

use craft\db\Query;
use Tests\Support\ResetTestDatabase;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/ResetTestDatabase.php';

try {
    $app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
} catch (Throwable $e) {
    throw new RuntimeException(
        'Craft bootstrap failed for tests. Run `composer test:setup` and verify plugin `.env.testing` CRAFT_DB_* values.',
        0,
        $e
    );
}

if (!class_exists(Craft::class) || !Craft::$app) {
    throw new RuntimeException('Craft application failed to bootstrap for integration tests.');
}

$environment = getenv('ENVIRONMENT') ?: '';
if ($environment !== 'testing') {
    throw new RuntimeException('Refusing to run tests outside ENVIRONMENT=testing. Configure phpunit.craft.xml/.env.testing.');
}

$db = Craft::$app->getDb();
if (!$db->tableExists('{{%plugins}}')) {
    throw new RuntimeException(
        'Testing database is not installed yet. Run `composer test:setup` first, then re-run tests.'
    );
}

$projectConfig = Craft::$app->getProjectConfig();
$pluginRows = (new Query())
    ->select(['handle', 'schemaVersion'])
    ->from('{{%plugins}}')
    ->where(['handle' => ['hyper']])
    ->all();

foreach ($pluginRows as $pluginRow) {
    $handle = (string)($pluginRow['handle'] ?? '');
    if (!$handle) {
        continue;
    }

    $key = 'plugins.' . $handle;
    $pluginConfig = $projectConfig->get($key);

    if (!$pluginConfig || empty($pluginConfig['enabled'])) {
        $projectConfig->set($key, [
            ...($pluginConfig ?: []),
            'edition' => 'standard',
            'enabled' => true,
            'schemaVersion' => (string)($pluginConfig['schemaVersion'] ?? $pluginRow['schemaVersion'] ?? ''),
        ]);
    }
}

$pluginsReflection = new ReflectionClass(Craft::$app->plugins);
foreach ([
    '_pluginsLoaded' => false,
    '_loadingPlugins' => false,
    '_plugins' => [],
] as $propertyName => $value) {
    if (!$pluginsReflection->hasProperty($propertyName)) {
        continue;
    }

    $property = $pluginsReflection->getProperty($propertyName);
    $property->setAccessible(true);
    $property->setValue(Craft::$app->plugins, $value);
}

$plugins = Craft::$app->plugins;

if (!$plugins->isPluginInstalled('hyper')) {
    $plugins->installPlugin('hyper');
} elseif (!$plugins->isPluginEnabled('hyper')) {
    $plugins->enablePlugin('hyper');
}

$hyper = $plugins->getPlugin('hyper');
if (!$hyper) {
    throw new RuntimeException('Hyper plugin failed to load for integration tests.');
}

$readOnly = $projectConfig->readOnly;
$projectConfig->readOnly = false;

try {
    $migrator = $hyper->getMigrator();

    foreach ($migrator->getNewMigrations() as $migration) {
        $migrator->migrateUp($migration);
    }

    if ($plugins->isPluginUpdatePending($hyper)) {
        $plugins->updatePluginVersionInfo($hyper);
    }
} finally {
    $projectConfig->readOnly = $readOnly;
}

ResetTestDatabase::resetHyperData();
