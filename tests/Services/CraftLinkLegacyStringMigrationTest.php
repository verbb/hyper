<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Json;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links as linkTypes;
use verbb\hyper\migrations\MigrateCraftLinkContent;
use verbb\hyper\migrations\MigrateCraftLinkField;
use verbb\hyper\migrations\MigrateOembedContent;

it('migrates native link content stored as plain strings by Craft 5.3 and 5.4', function() {
    $source = new craft\fields\Link([
        'name' => 'Native legacy link', 'handle' => F::handle('nativeLegacy'), 'types' => ['url', 'entry', 'email', 'tel'],
    ]);
    expect(Craft::$app->fields->saveField($source))->toBeTrue();
    $section = F::entrySectionWithField($source);
    $target = F::plainEntry($section, 'Legacy target');

    // Craft 5.3-5.4 stored Link content as the bare string (`dbType()` string, `LinkData::serialize()` returning the value).
    $legacy = [
        ['https://example.test/legacy', linkTypes\Url::class, 'https://example.test/legacy', 'https://example.test/legacy'],
        ["{entry:{$target->id}@{$target->siteId}:url}", linkTypes\Entry::class, [$target->id], $target->getUrl()],
        ['mailto:legacy@example.test', linkTypes\Email::class, 'legacy@example.test', 'mailto:legacy@example.test'],
        ['tel:+61312345678', linkTypes\Phone::class, '+61312345678', 'tel:+61312345678'],
    ];
    $owners = [];

    foreach ($legacy as $case) {
        $owner = F::plainEntry($section, 'Legacy owner');
        $uid = $owner->getFieldLayout()->getFieldByHandle($source->handle)->layoutElement->uid;
        $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
        $content = Json::decode((new Query())->select(['content'])->from('{{%elements_sites}}')->where($where)->scalar() ?? '') ?? [];
        $content[$uid] = $case[0];
        Craft::$app->getDb()->createCommand()->update('{{%elements_sites}}', ['content' => $content], $where)->execute();
        $owners[$owner->id] = $case;
    }

    $read = fn() => (new Query())->select(['elementId', 'content'])->from('{{%elements_sites}}')->where(['elementId' => array_keys($owners)])->orderBy('elementId')->all();
    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkField::class)->run()->ok)->toBeTrue();
    $result = Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class)->run();
    expect($result->ok)->toBeTrue();
    $converted = $read();
    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class)->run()->ok)->toBeTrue();
    expect($read())->toBe($converted);

    // Migration steps and subsequent reads normally run in separate requests.
    Craft::$app->set('fields', new \craft\services\Fields());
    Craft::$app->set('entries', new \craft\services\Entries());

    foreach ($owners as $id => [$stored, $class, $linkValue, $url]) {
        $links = Entry::find()->id($id)->one()->getFieldValue($source->handle);
        expect($links->count())->toBe(1, "Legacy value `{$stored}` was not migrated.");
        $link = $links->first();
        expect($link)->toBeInstanceOf($class);
        expect($link->linkValue)->toBe($linkValue);
        expect($link->getUrl())->toBe($url);
    }
});

it('leaves plain strings in fields without a matching link type for later content migrations', function() {
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig(linkTypes\Embed::class)]);
    $section = F::entrySectionWithField($field);
    $owner = F::plainEntry($section, 'Pending oEmbed owner');
    $uid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $read = fn() => Json::decode((new Query())->select(['content'])->from('{{%elements_sites}}')->where($where)->scalar() ?? '') ?? [];
    $url = 'https://www.youtube.com/watch?v=abc';

    // oEmbed content is still the bare URL string until its own content migration runs.
    $content = $read();
    $content[$uid] = $url;
    Craft::$app->getDb()->createCommand()->update('{{%elements_sites}}', ['content' => $content], $where)->execute();

    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class)->run()->ok)->toBeTrue();
    expect($read()[$uid] ?? null)->toBe($url);
    expect(Hyper::$plugin->createMigrator(MigrateOembedContent::class)->run()->ok)->toBeTrue();

    // Migration steps and subsequent reads normally run in separate requests.
    Craft::$app->set('fields', new \craft\services\Fields());
    Craft::$app->set('entries', new \craft\services\Entries());

    $links = Entry::find()->id($owner->id)->one()->getFieldValue($field->handle);
    expect($links->count())->toBe(1);
    expect($links->first())->toBeInstanceOf(linkTypes\Embed::class);
    expect($links->first()->getLinkUrl())->toBe($url);
});

it('leaves Craft Link strings for the Craft Link content migration when the oEmbed content migration runs first', function() {
    $source = new craft\fields\Link([
        'name' => 'Native legacy link', 'handle' => F::handle('nativeLegacy'), 'types' => ['url'],
    ]);
    expect(Craft::$app->fields->saveField($source))->toBeTrue();
    $section = F::entrySectionWithField($source);
    $owner = F::plainEntry($section, 'Legacy owner');
    $uid = $owner->getFieldLayout()->getFieldByHandle($source->handle)->layoutElement->uid;
    $where = ['elementId' => $owner->id, 'siteId' => $owner->siteId];
    $read = fn() => Json::decode((new Query())->select(['content'])->from('{{%elements_sites}}')->where($where)->scalar() ?? '') ?? [];
    $url = 'https://example.test/legacy';

    // Craft 5.3-5.4 stored Link content as the bare string.
    $content = $read();
    $content[$uid] = $url;
    Craft::$app->getDb()->createCommand()->update('{{%elements_sites}}', ['content' => $content], $where)->execute();

    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkField::class)->run()->ok)->toBeTrue();
    expect(Hyper::$plugin->createMigrator(MigrateOembedContent::class)->run()->ok)->toBeTrue();
    expect($read()[$uid] ?? null)->toBe($url);
    expect(Hyper::$plugin->createMigrator(MigrateCraftLinkContent::class)->run()->ok)->toBeTrue();

    // Migration steps and subsequent reads normally run in separate requests.
    Craft::$app->set('fields', new \craft\services\Fields());
    Craft::$app->set('entries', new \craft\services\Entries());

    $links = Entry::find()->id($owner->id)->one()->getFieldValue($source->handle);
    expect($links->count())->toBe(1);
    expect($links->first())->toBeInstanceOf(linkTypes\Url::class);
    expect($links->first()->getUrl())->toBe($url);
});
