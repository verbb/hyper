<?php

use craft\db\Query;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links;
use verbb\hyper\migrations\MigrateLinkContent;
use verbb\hyper\migrations\MigrateLinkitContent;

it('converts Craft 2 Linkit destinations through the current type mapping', function(string $type, mixed $value, string $handle, mixed $expected) {
    $field = F::hyperField();
    $converted = (new MigrateLinkitContent())->convertModel($field, [
        'type' => $type, $type => $value, 'customText' => 'Legacy label', 'target' => true,
    ]);
    expect($converted)->toBeArray();
    expect($converted[0]['linkTypeHandle'])->toBe($handle);
    expect($converted[0]['linkValue'])->toBe($expected);
    expect($converted[0]['linkText'])->toBe('Legacy label');
    expect($converted[0]['newWindow'])->toBeTrue();
})->with([
    'entry' => ['entry', [42], 'entry', 42],
    'category' => ['category', [43], 'category', 43],
    'asset' => ['asset', [44], 'asset', 44],
    'product' => ['product', [45], 'product', 45],
    'email' => ['email', 'hello@example.test', 'email', 'hello@example.test'],
    'phone' => ['tel', '0012345', 'phone', '0012345'],
    'zero phone' => ['tel', '0', 'phone', '0'],
    'custom URL' => ['custom', 'https://example.test/?a=1&b=2', 'url', 'https://example.test/?a=1&b=2'],
]);

it('retains configured Linkit handles and modern content when normalizing legacy values', function() {
    $field = F::hyperField();
    $field->migrationData = ['linkitTypes' => ['presseddigital\\linkit\\models\\Entry' => 'article']];
    $migrator = new MigrateLinkitContent();
    $legacy = $migrator->convertModel($field, ['type' => 'entry', 'entry' => [42]]);
    expect($legacy[0]['linkTypeHandle'])->toBe('article');
    $modern = $migrator->convertModel($field, [
        'type' => 'presseddigital\\linkit\\models\\Entry', 'value' => 99, 'entry' => [42],
    ]);
    expect($modern[0]['linkValue'])->toBe(99);
    expect($migrator->convertModel($field, ['type' => 'unknown', 'unknown' => 'untouched']))->toBeFalse();
});

it('repairs only missing element sites and preserves raw rows on conversion reruns', function(string $migrationClass, bool $singleRow) {
    $field = F::hyperField();
    $custom = new links\Entry();
    $custom->handle = 'article';
    $field->linkTypes = [F::linkTypeConfig(links\Url::class), F::linkTypeConfig($custom)];
    $migration = new $migrationClass();
    $site = new ReflectionProperty($migration, 'contentSiteId');
    $site->setValue($migration, 2);
    $rows = [
        ['type' => links\Entry::class, 'handle' => 'default-verbb-hyper-links-entry', 'linkValue' => 42, 'fields' => ['caption' => 'Keep'], 'extra' => ['keep' => true]],
        ['linkTypeHandle' => 'article', 'linkValue' => 43, 'linkSiteId' => null],
        ['linkTypeHandle' => 'article', 'linkValue' => 44, 'linkSiteId' => 0],
        ['linkTypeHandle' => 'article', 'linkValue' => 45, 'linkSiteId' => ''],
        ['linkTypeHandle' => 'article', 'linkValue' => 46, 'linkSiteId' => 3],
        ['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test'],
        ['linkTypeHandle' => 'missing-custom-type', 'linkValue' => 47, 'fields' => ['opaque' => 'Keep']],
    ];
    $expected = $rows;
    foreach ([0, 1, 2, 3] as $i) $expected[$i]['linkSiteId'] = 2;
    $input = $singleRow ? $rows[0] : $rows;
    expect($migration->convertModel($field, $input))->toBe($singleRow ? $expected[0] : $expected);
    expect($migration->convertModel($field, $singleRow ? $expected[0] : $expected))->toBeNull();
    $site->setValue($migration, null);
    expect($migration->convertModel($field, $input))->toBeNull();
})->with([MigrateLinkitContent::class, MigrateLinkContent::class])->with([false, true]);

it('persists migration site repairs with dry-run and repeat safety and rebuilds relations', function(string $migrationClass, bool $legacy) {
    [$primary, $secondary] = F::ensureSites(2);
    $field = F::hyperField(['multipleLinks' => true]);
    $owner = F::plainEntry(F::entrySection($field), 'Migration owner');
    $target = F::plainEntry(F::entrySection(allSites: true), 'Target');
    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $identity = $legacy
        ? ['type' => links\Entry::class, 'handle' => 'default-verbb-hyper-links-entry']
        : ['linkTypeHandle' => 'entry'];
    $rows = [
        [...$identity, 'linkValue' => $target->id, 'linkText' => 'Retain label', 'fields' => ['unknown-layout-uid' => ['keep' => 'value']], 'extensionData' => ['keep' => true]],
        [...$identity, 'linkValue' => $target->id, 'linkSiteId' => $secondary->id],
        ['linkTypeHandle' => 'unavailable-type', 'linkValue' => 'opaque', 'fields' => ['keep' => 'content']],
    ];
    $where = ['elementId' => $owner->id, 'siteId' => $primary->id];
    Craft::$app->db->createCommand()->update('{{%elements_sites}}', ['content' => new yii\db\JsonExpression([$uid => $rows])], $where)->execute();
    $read = fn() => (new Query())->select('content')->from('{{%elements_sites}}')->where($where)->scalar();
    $relations = fn() => array_map('intval', (new Query())->select('targetSiteId')->from('{{%hyper_links}}')->where(['ownerId' => $owner->id, 'ownerSiteId' => $primary->id])->orderBy('sortOrder')->column());
    $migration = new $migrationClass();
    $migration->fields = [(new Query())->from('{{%fields}}')->where(['id' => $field->id])->one()];
    $before = $read();
    $migration->dryRun = true;
    $migration->processFieldContent();
    expect($read())->toBe($before);
    expect($relations())->toBe([]);
    $migration->dryRun = false;
    $migration->processFieldContent();
    $rows[0]['linkSiteId'] = $primary->id;
    expect(Json::decode($read())[$uid])->toEqual($rows);
    expect($relations())->toBe([$primary->id, $secondary->id]);
    $after = $read();
    $migration->processFieldContent();
    expect($read())->toBe($after);
    expect($relations())->toBe([$primary->id, $secondary->id]);
})->with([MigrateLinkitContent::class, MigrateLinkContent::class])->with([false, true]);
