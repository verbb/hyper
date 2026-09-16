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
