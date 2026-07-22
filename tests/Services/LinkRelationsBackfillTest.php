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

    $existingCount = (new Query())
        ->from('{{%hyper_links}}')
        ->where([
            'ownerId' => $owner->id,
            'ownerSiteId' => $owner->siteId,
        ])
        ->count();
    expect((int)$existingCount)->toBe(1);

    $inserted = Hyper::$plugin->getLinkRelations()->backfillFromElementCache();

    expect($inserted)->toBe(0);
    expect((int)(new Query())->from('{{%hyper_links}}')->where([
        'ownerId' => $owner->id,
        'ownerSiteId' => $owner->siteId,
    ])->count())->toBe(1);
});
