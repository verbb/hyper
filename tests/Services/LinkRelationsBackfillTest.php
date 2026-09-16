<?php

declare(strict_types=1);

use craft\db\Query;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\LegacyElementCacheSeeder;
use verbb\hyper\Hyper;
use verbb\hyper\records\LinkRelation as LinkRelationRecord;

it('backfills hyper_links rows from legacy hyper_element_cache records', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinkPayloads(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target')],
        'Target entry',
    );
    $owner = HyperFixtureFactory::entryWithLinkPayloads(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target, 'Points at target')],
        'Owner entry',
    );

    foreach (LinkRelationRecord::findAll(['ownerId' => $owner->id]) as $record) {
        $record->delete();
    }

    // Simulate legacy installs that still have element-cache rows but no relation rows yet.
    LegacyElementCacheSeeder::ensureLegacyTable();
    LegacyElementCacheSeeder::seedFromOwner($field, $owner);

    expect((int)(new Query())->from('{{%hyper_links}}')->where(['ownerId' => $owner->id])->count())->toBe(0);

    $inserted = Hyper::$plugin->getLinkRelations()->backfillFromElementCache();

    expect($inserted)->toBeGreaterThan(0);

    $relation = (new Query())
        ->from('{{%hyper_links}}')
        ->where([
            'ownerId' => $owner->id,
            'fieldId' => $field->id,
            'targetId' => $target->id,
        ])
        ->one();

    expect($relation)->not->toBeNull();
    expect((int)$relation['targetSiteId'])->toBe($owner->siteId);
});

it('skips duplicate rows when backfilling element cache records', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryTargets(1, $section)[0];
    $owner = HyperFixtureFactory::entryWithLinkPayloads(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target)],
        'Owner entry',
    );

    LegacyElementCacheSeeder::seedFromOwner($field, $owner);
    $other = HyperFixtureFactory::entryWithLinkPayloads($section, [HyperFixtureFactory::entryLinkPayload($target)], 'Unindexed owner');
    LegacyElementCacheSeeder::seedFromOwner($field, $other);
    LinkRelationRecord::deleteAll(['ownerId' => $other->id]);
    expect((int)(new Query())->from('{{%hyper_element_cache}}')->count())->toBe(2);

    $read = fn($id) => (new Query())->from('{{%hyper_links}}')->where(['ownerId' => $id])->orderBy('id')->all();
    $before = $read($owner->id);
    expect($before)->toHaveCount(1);
    expect($read($other->id))->toBe([]);

    // The same run must skip the duplicate AND insert the missing relation.
    expect(Hyper::$plugin->linkRelations->backfillFromElementCache())->toBe(1);
    expect($read($owner->id))->toBe($before);
    $inserted = $read($other->id);
    expect($inserted)->toHaveCount(1);
    expect((int)$inserted[0]['targetId'])->toBe($target->id);
    expect((int)$inserted[0]['targetSiteId'])->toBe($other->siteId);
    expect(Hyper::$plugin->linkRelations->backfillFromElementCache())->toBe(0);
    expect($read($owner->id))->toBe($before);
    expect($read($other->id))->toBe($inserted);
});
