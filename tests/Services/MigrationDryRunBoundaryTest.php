<?php

use craft\db\Query;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\fields\HyperField;
use verbb\hyper\migrations\MigrateCraftLinkField;

it('leaves source field rows and project config unchanged during a migration dry run', function() {
    $source = new craft\fields\Link(['name' => 'Dry-run source', 'handle' => F::handle('dryRunSource'), 'types' => ['url']]);
    expect(Craft::$app->fields->saveField($source))->toBeTrue();
    $read = fn() => (new Query())->from('{{%fields}}')->where(['id' => $source->id])->one();
    $before = $read();
    $config = Craft::$app->projectConfig->get('fields.' . $source->uid);
    $migration = new MigrateCraftLinkField(['dryRun' => true]);
    expect($migration->safeUp())->toBeTrue();
    expect($read())->toBe($before);
    expect(Craft::$app->projectConfig->get('fields.' . $source->uid))->toBe($config);
    expect(Craft::$app->fields->getFieldById($source->id))->toBeInstanceOf(craft\fields\Link::class);
    // The same migration must still perform the conversion when dry-run is disabled.
    $migration->dryRun = false;
    expect($migration->safeUp())->toBeTrue();
    expect(Craft::$app->fields->getFieldById($source->id))->toBeInstanceOf(HyperField::class);
    expect($read()['type'])->toBe(HyperField::class);
});
