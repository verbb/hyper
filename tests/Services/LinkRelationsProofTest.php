<?php

declare(strict_types=1);

use craft\db\Query;
use craft\elements\Entry;
use Tests\Support\Fixtures\HyperFixtureFactory;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;

it('syncs relation rows from serialized link payloads without loading elements', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $targets = HyperFixtureFactory::entries(2, $section);
    $owner = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($targets[0], 'First target')],
        'Owner entry',
    );

    $links = $owner->getFieldValue($field->handle);

    $profile = QueryProfiler::profile(function() use ($field, $owner, $links): int {
        Hyper::$plugin->getLinkRelations()->syncFromLinkCollection($field, $owner, $links);

        return (int)(new Query())
            ->from('{{%hyper_links}}')
            ->where([
                'fieldId' => $field->id,
                'ownerId' => $owner->id,
                'ownerSiteId' => $owner->siteId,
            ])
            ->count();
    });

    expect($profile['resultSize'])->toBe(1);
    expect($profile['queries'])->toBeLessThanOrEqual(4);

    $row = (new Query())
        ->from('{{%hyper_links}}')
        ->where([
            'fieldId' => $field->id,
            'ownerId' => $owner->id,
            'targetId' => $targets[0]->id,
        ])
        ->one();

    expect($row)->not->toBeNull();
    expect((int)$row['targetId'])->toBe($targets[0]->id);
});

it('supports reverse lookups through the relations table', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/target')],
        'Target entry',
    );
    $owner = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::entryLinkPayload($target, 'Points at target')],
        'Owner entry',
    );

    Hyper::$plugin->getLinkRelations()->syncFromLinkCollection(
        $field,
        $owner,
        $owner->getFieldValue($field->handle),
    );

    $related = Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
        'elementType' => Entry::class,
        'relatedTo' => [
            'field' => $field->handle,
            'targetElement' => $target,
        ],
    ])?->ids();

    expect($related)->toContain($owner->id);
});

it('returns null for incomplete reverse lookup params', function() {
    expect(Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([]))->toBeNull();
    expect(Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
        'relatedTo' => ['field' => 'someField'],
    ]))->toBeNull();
});

it('returns an empty result query when no owners link to the target', function() {
    $field = HyperFixtureFactory::hyperField();
    $section = HyperFixtureFactory::entrySection($field);
    $target = HyperFixtureFactory::entryWithLinks(
        $section,
        [HyperFixtureFactory::urlLinkPayload('https://example.test/orphan-target')],
        'Unlinked target',
    );

    $related = Hyper::$plugin->getLinkRelations()->getRelatedElementsQuery([
        'elementType' => Entry::class,
        'relatedTo' => [
            'field' => $field->handle,
            'targetElement' => $target,
        ],
    ])?->ids();

    expect($related)->toBe([]);
});
