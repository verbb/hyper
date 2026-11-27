<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\migrations\MigrateCraftLinkField;
use verbb\hyper\variables\HyperVariable;

it('keeps native Craft Link content migration discoverable after converting its fields', function() {
    $source = new craft\fields\Link([
        'name' => 'Native migration source',
        'handle' => F::handle('nativeMigration'),
        'types' => ['url'],
    ]);
    expect(Craft::$app->fields->saveField($source))->toBeTrue();
    $navigation = new HyperVariable();
    expect($navigation->getSettingsNavItems())->toHaveKey('migrate-craft-link');

    $result = Hyper::$plugin->createMigrator(MigrateCraftLinkField::class)->run();
    expect($result->ok)->toBeTrue();
    expect(Craft::$app->fields->getFieldById($source->id))->toBeInstanceOf(HyperField::class);
    expect(Hyper::$plugin->getMigrations()->getSource('craft-link')['fieldCount'])->toBe(0);
    expect($navigation->getSettingsNavItems())->toHaveKey('migrate-craft-link');
});

it('shows the Flipbox Link migration when its fields exist without the plugin', function(string $type) {
    $db = Craft::$app->getDb();
    $insert = function(string $fieldType, string $prefix) use ($db): int {
        $db->createCommand()->insert('{{%fields}}', [
            'name' => 'Uninstalled plugin field',
            'handle' => F::handle($prefix),
            'context' => 'global',
            'type' => $fieldType,
            'settings' => '{}',
            'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    };
    $navigation = new HyperVariable();
    expect(Craft::$app->getPlugins()->isPluginInstalled('link'))->toBeFalse();
    expect($navigation->getSettingsNavItems())->not->toHaveKey('migrate-link');
    $fieldIds = [];

    try {
        $fieldIds[] = $insert($type, 'flipboxLink');
        // Other plugin sources still need their plugin installed.
        $fieldIds[] = $insert('presseddigital\\linkit\\fields\\LinkitField', 'linkit');
        $items = $navigation->getSettingsNavItems();

        expect($items)->toHaveKey('migrate-link');
        expect($items['migrate-link']['url'])->toContain('hyper/settings/migrate/link');
        expect($items)->not->toHaveKey('migrate-linkit');
        expect(Hyper::$plugin->getMigrations()->getSource('linkit')['fieldCount'])->toBe(1);
    } finally {
        $db->createCommand()->delete('{{%fields}}', ['id' => $fieldIds])->execute();
    }

    expect($navigation->getSettingsNavItems())->not->toHaveKey('migrate-link');
})->with([
    'current namespace' => 'flipbox\\craft\\link\\fields\\Link',
    'pre-1.0 namespace' => 'flipbox\\link\\fields\\Link',
]);
